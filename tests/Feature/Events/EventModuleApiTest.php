<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Member;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\Venue;
use Tests\Feature\Iam\IamTestCase;

final class EventModuleApiTest extends IamTestCase
{
  private function createMinistry(): CmsMinistry
  {
    return CmsMinistry::query()->create([
      'name' => 'Marketplace Leadership',
      'slug' => 'marketplace-leadership-test',
      'is_active' => true,
      'sort_order' => 1,
    ]);
  }

  private function createCountry(): CmsCountry
  {
    return CmsCountry::query()->create([
      'name' => 'Nigeria',
      'slug' => 'nigeria-test',
      'code' => 'NG',
      'is_active' => true,
      'sort_order' => 1,
    ]);
  }

  private function createVenue(?CmsCountry $country = null): Venue
  {
    return Venue::query()->create([
      'name' => 'Main Auditorium',
      'slug' => 'main-auditorium-test',
      'country_id' => $country?->id,
      'capacity' => 200,
      'status' => 'active',
    ]);
  }

  public function test_public_can_list_and_view_published_events(): void
  {
    $ministry = $this->createMinistry();
    $country = $this->createCountry();
    $venue = $this->createVenue($country);

    $event = Event::query()->create([
      'ministry_id' => $ministry->id,
      'venue_id' => $venue->id,
      'country_id' => $country->id,
      'title' => 'Kingdom Leadership Summit',
      'slug' => 'kingdom-leadership-summit-test',
      'summary' => 'Annual leadership gathering.',
      'starts_at' => now()->addMonth(),
      'ends_at' => now()->addMonth()->addDays(2),
      'timezone' => 'UTC',
      'capacity' => 100,
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $draftEvent = Event::query()->create([
      'title' => 'Draft Event',
      'slug' => 'draft-event-test',
      'starts_at' => now()->addMonth(),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Draft,
    ]);

    $this->getJson('/api/v1/public/events')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $event->uuid);

    $this->getJson("/api/v1/public/events/{$event->uuid}")
      ->assertOk()
      ->assertJsonPath('data.event.title', 'Kingdom Leadership Summit');

    $this->getJson("/api/v1/public/events/{$draftEvent->uuid}")
      ->assertNotFound();
  }

  public function test_admin_can_create_and_publish_event(): void
  {
    $ministry = $this->createMinistry();
    $country = $this->createCountry();
    $venue = $this->createVenue($country);

    $response = $this->postJson('/api/v1/events', [
      'ministry_id' => $ministry->id,
      'venue_id' => $venue->id,
      'country_id' => $country->id,
      'title' => 'Executive Leadership Masterclass',
      'starts_at' => now()->addWeeks(3)->toIso8601String(),
      'ends_at' => now()->addWeeks(3)->addDay()->toIso8601String(),
      'capacity' => 50,
      'visibility' => EventVisibility::Public->value,
      'status' => EventStatus::Draft->value,
    ])->assertCreated();

    $eventUuid = $response->json('data.event.id');
    $this->assertDatabaseHas('events', ['title' => 'Executive Leadership Masterclass']);

    $this->postJson("/api/v1/events/{$eventUuid}/publish")
      ->assertOk()
      ->assertJsonPath('data.event.status', EventStatus::Published->value);

    $this->assertDatabaseHas('events', [
      'title' => 'Executive Leadership Masterclass',
      'status' => EventStatus::Published->value,
    ]);
  }

  public function test_public_registration_links_existing_member_by_email(): void
  {
    $event = Event::query()->create([
      'title' => 'Members Only Gathering',
      'slug' => 'members-only-gathering-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 50,
    ]);

    $member = Member::factory()->create(['email' => 'registrant@example.com']);

    $response = $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->id,
      'registrant' => [
        'name' => 'Registrant Test',
        'email' => 'registrant@example.com',
        'phone' => '+10000000000',
      ],
      'consent_accepted' => true,
    ])->assertCreated();

    $response->assertJsonPath('data.registration.registrant.is_member', true);

    $this->assertDatabaseHas('event_registrations', [
      'event_id' => $event->id,
      'member_id' => $member->id,
      'status' => RegistrationStatus::Submitted->value,
    ]);
  }

  public function test_admin_can_check_in_a_registration(): void
  {
    $event = Event::query()->create([
      'title' => 'Check-in Test Event',
      'slug' => 'check-in-test-event',
      'starts_at' => now()->addDays(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 20,
    ]);

    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'Guest Attendee',
      'guest_email' => 'guest@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000001',
      'status' => RegistrationStatus::Approved,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-in", [])
      ->assertCreated()
      ->assertJsonStructure(['data' => ['check_in' => ['id', 'checked_in_at']]]);

    $this->assertDatabaseHas('event_registrations', [
      'id' => $registration->id,
      'status' => RegistrationStatus::CheckedIn->value,
    ]);

    $this->assertDatabaseHas('event_check_ins', [
      'registration_id' => $registration->id,
    ]);
  }

  public function test_public_registration_accepts_event_uuid(): void
  {
    $event = Event::query()->create([
      'title' => 'UUID Registration Event',
      'slug' => 'uuid-registration-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 50,
    ]);

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'UUID Registrant',
        'email' => 'uuid-registrant@example.com',
      ],
      'consent_accepted' => true,
    ])
      ->assertCreated()
      ->assertJsonPath('data.registration.event_id', $event->uuid);
  }

  public function test_public_registration_rejects_closed_event(): void
  {
    $event = Event::query()->create([
      'title' => 'Closed Registration Event',
      'slug' => 'closed-registration-event-test',
      'starts_at' => now()->addWeeks(2),
      'registration_deadline' => now()->subDay(),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 50,
    ]);

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Late Registrant',
        'email' => 'late@example.com',
      ],
      'consent_accepted' => true,
    ])
      ->assertStatus(422)
      ->assertJsonPath('success', false);
  }

  public function test_public_event_show_returns_numeric_registrations_count(): void
  {
    $event = Event::query()->create([
      'title' => 'Count Test Event',
      'slug' => 'count-test-event',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 100,
    ]);

    EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'Counted Guest',
      'guest_email' => 'counted@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000001',
      'status' => RegistrationStatus::Submitted,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/public/events/{$event->uuid}")
      ->assertOk()
      ->assertJsonPath('data.event.registrations_count', 1);

    $this->assertIsInt($response->json('data.event.registrations_count'));
    $this->assertNull($response->json('data.event.category'));
  }

  public function test_public_registration_rejects_duplicate_guest_email(): void
  {
    $event = Event::query()->create([
      'title' => 'Duplicate Guest Event',
      'slug' => 'duplicate-guest-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 50,
    ]);

    $payload = [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'First Guest',
        'email' => 'duplicate-guest@example.com',
      ],
      'consent_accepted' => true,
    ];

    $this->postJson('/api/v1/public/events/registrations', $payload)->assertCreated();

    $this->postJson('/api/v1/public/events/registrations', [
      ...$payload,
      'registrant' => [
        'name' => 'Second Guest',
        'email' => 'duplicate-guest@example.com',
      ],
    ])
      ->assertOk()
      ->assertJsonPath('message', 'Registration updated.');
  }

  public function test_public_registration_rejects_full_event(): void
  {
    $event = Event::query()->create([
      'title' => 'Full Event',
      'slug' => 'full-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 1,
    ]);

    EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'First Guest',
      'guest_email' => 'first@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000001',
      'status' => RegistrationStatus::Submitted,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Second Guest',
        'email' => 'second@example.com',
      ],
      'consent_accepted' => true,
    ])
      ->assertStatus(422)
      ->assertJsonPath('message', 'This event is at capacity.');
  }

  public function test_admin_can_manage_registration_form_config(): void
  {
    $event = Event::query()->create([
      'title' => 'Form Config Event',
      'slug' => 'form-config-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $this->getJson("/api/v1/events/{$event->uuid}/registration-field-settings")
      ->assertOk()
      ->assertJsonPath('data.settings.0.field_key', 'name');

    $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
      'settings' => [
        ['field_key' => 'name', 'label' => 'Legal name', 'is_enabled' => true, 'is_required' => true],
        ['field_key' => 'phone', 'label' => 'Mobile phone', 'is_enabled' => true, 'is_required' => true],
      ],
    ])->assertOk();

    $questionResponse = $this->postJson("/api/v1/events/{$event->uuid}/registration-questions", [
      'question' => 'What is your ministry focus?',
      'answer_type' => 'textarea',
      'is_required' => true,
    ])->assertCreated();

    $questionUuid = $questionResponse->json('data.question.id');

    $this->getJson("/api/v1/public/events/{$event->uuid}")
      ->assertOk()
      ->assertJsonPath('data.event.registration_questions.0.question', 'What is your ministry focus?');

    $this->deleteJson("/api/v1/events/registration-questions/{$questionUuid}")
      ->assertOk();
  }

  public function test_admin_can_create_on_site_registration(): void
  {
    $event = Event::query()->create([
      'title' => 'On-site Registration Event',
      'slug' => 'on-site-registration-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'check_in_enabled' => true,
    ]);

    $response = $this->postJson('/api/v1/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Walk-in Guest',
        'email' => 'walkin@example.com',
        'phone' => '+10000000001',
      ],
      'consent_accepted' => true,
      'check_in_immediately' => true,
    ])->assertCreated();

    $response->assertJsonPath('data.registration.registrant.name', 'Walk-in Guest');

    $this->assertDatabaseHas('event_registrations', [
      'event_id' => $event->id,
      'guest_email' => 'walkin@example.com',
      'source' => 'on_site',
    ]);
  }

  public function test_admin_registration_show_includes_history(): void
  {
    $event = Event::query()->create([
      'title' => 'History Event',
      'slug' => 'history-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'History Guest',
      'guest_email' => 'history@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000099',
      'status' => RegistrationStatus::Submitted,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    $this->getJson("/api/v1/events/registrations/{$registration->uuid}")
      ->assertOk()
      ->assertJsonStructure([
        'data' => [
          'registration' => [
            'id',
            'timeline',
            'audit_logs',
            'status_transitions',
          ],
        ],
      ]);
  }

  public function test_admin_can_check_out_registration(): void
  {
    $event = Event::query()->create([
      'title' => 'Checkout Event',
      'slug' => 'checkout-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'check_in_enabled' => true,
    ]);

    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'Checked In Guest',
      'guest_email' => 'checkedin@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000088',
      'status' => RegistrationStatus::CheckedIn,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-out")
      ->assertOk()
      ->assertJsonStructure(['data' => ['attendance' => ['id']]]);

    $this->assertDatabaseHas('event_attendance_histories', [
      'registration_id' => $registration->id,
      'status' => 'checked_out',
    ]);
  }

  public function test_admin_can_list_attendance(): void
  {
    $event = Event::query()->create([
      'title' => 'Attendance Event',
      'slug' => 'attendance-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'Attendee',
      'guest_email' => 'attendee@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000077',
      'status' => RegistrationStatus::CheckedIn,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    \App\Modules\Events\Models\EventAttendanceHistory::query()->create([
      'event_id' => $event->id,
      'registration_id' => $registration->id,
      'status' => 'present',
      'occurred_at' => now(),
    ]);

    $this->getJson('/api/v1/events/attendance')
      ->assertOk()
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_registration_form_config_is_enforced(): void
  {
    $event = Event::query()->create([
      'title' => 'Enforced Form Event',
      'slug' => 'enforced-form-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
      'settings' => [
        ['field_key' => 'name', 'label' => 'Legal name', 'is_enabled' => true, 'is_required' => true],
        ['field_key' => 'phone', 'label' => 'Mobile phone', 'is_enabled' => true, 'is_required' => true],
      ],
    ])->assertOk();

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Missing Phone Guest',
        'email' => 'missing-phone@example.com',
      ],
      'consent_accepted' => true,
    ])->assertStatus(422);
  }

  public function test_on_site_registration_links_existing_member(): void
  {
    $event = Event::query()->create([
      'title' => 'Member Link Event',
      'slug' => 'member-link-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $member = Member::factory()->create([
      'email' => 'member-link@example.com',
      'first_name' => 'Linked',
      'last_name' => 'Member',
    ]);

    $this->postJson('/api/v1/events/registrations', [
      'event_id' => $event->uuid,
      'member_id' => $member->uuid,
      'consent_accepted' => true,
    ])->assertCreated();

    $this->assertDatabaseHas('event_registrations', [
      'event_id' => $event->id,
      'member_id' => $member->id,
      'guest_email' => null,
    ]);
  }

  public function test_public_events_filter_by_country_and_ministry(): void
  {
    $ministry = $this->createMinistry();
    $country = $this->createCountry();

    $event = Event::query()->create([
      'ministry_id' => $ministry->id,
      'country_id' => $country->id,
      'title' => 'Filtered Event',
      'slug' => 'filtered-event-test',
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    $this->getJson('/api/v1/public/events?ministry_id='.$ministry->id.'&country_id='.$country->uuid)
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $event->uuid);
  }
}
