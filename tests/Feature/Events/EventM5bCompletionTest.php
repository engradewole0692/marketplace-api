<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Member;
use App\Modules\Events\Enums\CouponDiscountType;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\PaymentMethodType;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\VolunteerAssignmentStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventCoupon;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Models\EventVolunteerRole;
use App\Modules\Events\Services\CheckInTokenService;
use Tests\Feature\Iam\IamTestCase;

final class EventM5bCompletionTest extends IamTestCase
{
  private function publishedEvent(array $overrides = []): Event
  {
    return Event::query()->create(array_merge([
      'title' => 'M5B Event',
      'slug' => 'm5b-event-'.uniqid(),
      'starts_at' => now()->addWeek(),
      'ends_at' => now()->addWeek()->addDay(),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'check_in_enabled' => true,
      'certificate_enabled' => true,
    ], $overrides));
  }

  private function registration(Event $event, array $overrides = []): EventRegistration
  {
    return EventRegistration::query()->create(array_merge([
      'event_id' => $event->id,
      'guest_name' => 'Volunteer Guest',
      'guest_email' => 'guest+'.uniqid().'@example.com',
      'registration_number' => 'EVT-'.$event->id.'-'.uniqid(),
      'status' => RegistrationStatus::Approved,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ], $overrides));
  }

  public function test_admin_can_issue_check_in_token_and_scan(): void
  {
    $event = $this->publishedEvent();
    $registration = $this->registration($event);

    $issue = $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-in-token")
      ->assertCreated();

    $token = $issue->json('data.token');
    $this->assertIsString($token);
    $this->assertDatabaseHas('event_check_in_tokens', ['registration_id' => $registration->id]);

    $this->postJson('/api/v1/events/check-in/scan', ['token' => $token])
      ->assertCreated()
      ->assertJsonStructure(['data' => ['check_in' => ['id']]]);

    $this->assertDatabaseHas('event_registrations', [
      'id' => $registration->id,
      'status' => RegistrationStatus::CheckedIn->value,
    ]);
  }

  public function test_certificate_issue_and_public_verify(): void
  {
    $event = $this->publishedEvent();
    $registration = $this->registration($event, ['status' => RegistrationStatus::Attended]);

    $response = $this->postJson('/api/v1/events/certificates/issue', [
      'registration_id' => $registration->uuid,
    ])->assertCreated();

    $code = $response->json('data.certificate.verification_code');
    $this->assertIsString($code);

    $this->assertDatabaseHas('event_certificate_issuances', [
      'registration_id' => $registration->id,
      'verification_code' => $code,
    ]);

    $this->getJson("/api/v1/public/events/certificates/verify/{$code}")
      ->assertOk()
      ->assertJsonPath('data.certificate.verification_code', $code);

    $this->getJson('/api/v1/public/events/certificates/verify/BADCODE')
      ->assertNotFound();
  }

  public function test_export_registrations_csv(): void
  {
    $event = $this->publishedEvent();
    $this->registration($event);

    $response = $this->postJson('/api/v1/events/exports', [
      'event_id' => $event->uuid,
      'export_type' => 'registrations',
      'format' => 'csv',
    ])->assertCreated();

    $this->assertDatabaseHas('event_export_jobs', [
      'event_id' => $event->id,
      'export_type' => 'registrations',
      'format' => 'csv',
    ]);
    $this->assertEquals('completed', $response->json('data.export.status'));
  }

  public function test_volunteer_assignment_flow(): void
  {
    $event = $this->publishedEvent();
    $registration = $this->registration($event, ['volunteer_interest' => true]);

    $roleResponse = $this->postJson("/api/v1/events/{$event->uuid}/volunteer-roles", [
      'name' => 'Ushering',
      'slots' => 3,
    ])->assertCreated();

    $roleUuid = $roleResponse->json('data.role.id');

    $assignmentResponse = $this->postJson('/api/v1/events/volunteer-assignments', [
      'registration_id' => $registration->uuid,
      'role_id' => $roleUuid,
      'status' => VolunteerAssignmentStatus::Assigned->value,
    ])->assertCreated();

    $this->assertDatabaseHas('event_volunteer_assignments', [
      'registration_id' => $registration->id,
      'status' => VolunteerAssignmentStatus::Assigned->value,
    ]);

    $assignmentUuid = $assignmentResponse->json('data.assignment.id');
    $this->putJson("/api/v1/events/volunteer-assignments/{$assignmentUuid}", [
      'status' => VolunteerAssignmentStatus::Completed->value,
      'performance_score' => 95,
    ])->assertOk();

    $this->assertDatabaseHas('event_volunteer_assignments', [
      'status' => VolunteerAssignmentStatus::Completed->value,
      'performance_score' => 95,
    ]);
  }

  public function test_session_conflict_detection(): void
  {
    $event = $this->publishedEvent();

    $first = $this->postJson("/api/v1/events/{$event->uuid}/sessions", [
      'title' => 'Opening Session',
      'starts_at' => now()->addWeek()->setTime(9, 0)->toIso8601String(),
      'ends_at' => now()->addWeek()->setTime(10, 0)->toIso8601String(),
      'room' => 'Main Hall',
    ])->assertCreated();

    $this->postJson("/api/v1/events/{$event->uuid}/sessions", [
      'title' => 'Overlapping Session',
      'starts_at' => now()->addWeek()->setTime(9, 30)->toIso8601String(),
      'ends_at' => now()->addWeek()->setTime(10, 30)->toIso8601String(),
      'room' => 'Main Hall',
    ])->assertStatus(422);

    $this->assertDatabaseCount('event_sessions', 1);
  }

  public function test_paid_event_payment_offline_approve(): void
  {
    $event = $this->publishedEvent([
      'is_paid' => true,
      'price' => 50.00,
      'currency' => 'USD',
    ]);
    $registration = $this->registration($event);

    $payment = EventRegistrationPayment::query()->create([
      'registration_id' => $registration->id,
      'event_id' => $event->id,
      'amount' => 50.00,
      'currency' => 'USD',
      'status' => PaymentStatus::Pending,
      'payment_method' => PaymentMethodType::Offline,
    ]);

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/payments/offline", [
      'notes' => 'Received cash at desk',
    ])->assertOk();

    $this->assertDatabaseHas('event_registration_payments', [
      'id' => $payment->id,
      'status' => PaymentStatus::Paid->value,
    ]);
  }

  public function test_coupon_apply_reduces_price(): void
  {
    $event = $this->publishedEvent([
      'is_paid' => true,
      'price' => 100.00,
    ]);
    $registration = $this->registration($event);

    EventRegistrationPayment::query()->create([
      'registration_id' => $registration->id,
      'event_id' => $event->id,
      'amount' => 100.00,
      'currency' => 'USD',
      'status' => PaymentStatus::Pending,
      'payment_method' => PaymentMethodType::Offline,
    ]);

    EventCoupon::query()->create([
      'event_id' => $event->id,
      'code' => 'SAVE50',
      'discount_type' => CouponDiscountType::Percent,
      'discount_value' => 50,
      'is_active' => true,
    ]);

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/payments/coupon", [
      'coupon_code' => 'SAVE50',
    ])->assertOk();

    $payment = EventRegistrationPayment::query()->where('registration_id', $registration->id)->first();
    $this->assertNotNull($payment);
    $this->assertEquals(50.0, (float) $payment->amount);
  }

  public function test_public_events_supports_featured_and_past_filters(): void
  {
    $featured = $this->publishedEvent([
      'title' => 'Featured Event',
      'is_featured' => true,
      'starts_at' => now()->addWeeks(2),
    ]);
    $past = $this->publishedEvent([
      'title' => 'Past Event',
      'is_featured' => false,
      'starts_at' => now()->subMonth(),
      'ends_at' => now()->subMonth()->addDay(),
    ]);

    $this->getJson('/api/v1/public/events?featured=1')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $featured->uuid);

    $this->getJson('/api/v1/public/events?upcoming_only=0&past_only=1')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $past->uuid);
  }
}
