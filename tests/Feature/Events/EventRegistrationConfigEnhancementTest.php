<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Member;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\RegistrantExportBuilder;
use Tests\Feature\Iam\IamTestCase;

final class EventRegistrationConfigEnhancementTest extends IamTestCase
{
  private function publishedEvent(array $overrides = []): Event
  {
    return Event::query()->create(array_merge([
      'title' => 'Lagos Marketplace Summit',
      'slug' => 'lagos-marketplace-summit-'.uniqid(),
      'starts_at' => now()->addDay(),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'check_in_enabled' => true,
      'capacity' => 500,
    ], $overrides));
  }

  public function test_field_settings_include_visibility_and_occupation_catalog(): void
  {
    $event = $this->publishedEvent();

    $response = $this->getJson("/api/v1/events/{$event->uuid}/registration-field-settings")
      ->assertOk();

    $keys = collect($response->json('data.settings'))->pluck('field_key');
    $this->assertTrue($keys->contains('occupation'));
    $this->assertTrue($keys->contains('accommodation_required'));
    $this->assertTrue($keys->contains('country'));

    $name = collect($response->json('data.settings'))->firstWhere('field_key', 'name');
    $this->assertTrue($name['show_on_public']);
    $this->assertTrue($name['show_on_quick']);
  }

  public function test_public_and_quick_form_schemas_respect_visibility(): void
  {
    $event = $this->publishedEvent();

    $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
      'settings' => [
        [
          'field_key' => 'name',
          'is_enabled' => true,
          'is_required' => true,
          'show_on_public' => true,
          'show_on_quick' => true,
        ],
        [
          'field_key' => 'email',
          'is_enabled' => true,
          'is_required' => true,
          'show_on_public' => true,
          'show_on_quick' => true,
        ],
        [
          'field_key' => 'phone',
          'is_enabled' => true,
          'is_required' => false,
          'show_on_public' => true,
          'show_on_quick' => true,
        ],
        [
          'field_key' => 'occupation',
          'is_enabled' => true,
          'is_required' => false,
          'show_on_public' => false,
          'show_on_quick' => true,
        ],
        [
          'field_key' => 'accommodation_required',
          'is_enabled' => true,
          'is_required' => false,
          'show_on_public' => true,
          'show_on_quick' => false,
        ],
      ],
    ])->assertOk();

    $question = $this->postJson("/api/v1/events/{$event->uuid}/registration-questions", [
      'question' => 'Are you staying in provided accommodation?',
      'answer_type' => 'yes_no',
      'is_required' => false,
      'show_on_public' => true,
      'show_on_quick' => false,
    ])->assertCreated()->json('data.question.id');

    $publicForm = $this->getJson("/api/v1/public/events/{$event->uuid}/registration-form")
      ->assertOk()
      ->json('data.form.fields');

    $publicKeys = collect($publicForm)->pluck('key');
    $this->assertTrue($publicKeys->contains('name'));
    $this->assertTrue($publicKeys->contains('accommodation_required'));
    $this->assertFalse($publicKeys->contains('occupation'));
    $this->assertTrue($publicKeys->contains('question_'.$question));

    $quickForm = $this->getJson("/api/v1/events/{$event->uuid}/registration-form?context=quick")
      ->assertOk()
      ->json('data.form.fields');

    $quickKeys = collect($quickForm)->pluck('key');
    $this->assertTrue($quickKeys->contains('occupation'));
    $this->assertFalse($quickKeys->contains('accommodation_required'));
    $this->assertFalse($quickKeys->contains('question_'.$question));
  }

  public function test_required_fields_and_custom_fields_are_enforced_on_public_registration(): void
  {
    $event = $this->publishedEvent();

    $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
      'settings' => [
        ['field_key' => 'name', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
        ['field_key' => 'email', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
        ['field_key' => 'occupation', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true, 'show_on_quick' => true],
      ],
    ])->assertOk();

    $questionId = $this->postJson("/api/v1/events/{$event->uuid}/registration-questions", [
      'question' => 'Organization',
      'answer_type' => 'text',
      'is_required' => true,
      'show_on_public' => true,
    ])->assertCreated()->json('data.question.id');

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
      ],
      'consent_accepted' => true,
    ])->assertStatus(422);

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
      ],
      'occupation' => 'Engineer',
      'answers' => [$questionId => 'Analytical Engines Ltd'],
      'consent_accepted' => true,
    ])->assertCreated();

    $registration = EventRegistration::query()->where('guest_email', 'ada@example.com')->first();
    $this->assertNotNull($registration);
    $this->assertSame('Engineer', $registration->metadata['profile']['occupation'] ?? null);
    $this->assertDatabaseHas('event_registration_question_answers', [
      'registration_id' => $registration->id,
      'answer_text' => 'Analytical Engines Ltd',
    ]);
  }

  public function test_existing_person_search_reuse_and_register_check_in(): void
  {
    $event = $this->publishedEvent();
    $member = Member::factory()->create([
      'email' => 'john.venue@example.com',
      'phone' => '+2348011111111',
      'first_name' => 'John',
      'last_name' => 'Doe',
    ]);

    $search = $this->getJson('/api/v1/events/registrations/search?q=+2348011111111')
      ->assertOk()
      ->json('data.members');

    $this->assertNotEmpty($search);
    $this->assertSame($member->uuid, $search[0]['id']);

    $response = $this->postJson('/api/v1/events/registrations', [
      'event_id' => $event->uuid,
      'member_id' => $member->uuid,
      'consent_accepted' => true,
      'check_in_immediately' => true,
      'occupation' => 'Pastor',
    ])->assertCreated();

    $registrationUuid = $response->json('data.registration.id');
    $registration = EventRegistration::query()->where('uuid', $registrationUuid)->firstOrFail();

    $this->assertSame($member->id, $registration->member_id);
    $this->assertSame(RegistrationStatus::CheckedIn, $registration->status);
    $this->assertDatabaseHas('event_check_ins', ['registration_id' => $registration->id]);
    $this->assertDatabaseHas('event_attendance_histories', [
      'registration_id' => $registration->id,
      'status' => 'present',
    ]);

    // Duplicate prevention: same member + event returns existing registration.
    $again = $this->postJson('/api/v1/events/registrations', [
      'event_id' => $event->uuid,
      'member_id' => $member->uuid,
      'consent_accepted' => true,
    ])->assertOk();

    $this->assertSame($registrationUuid, $again->json('data.registration.id'));
    $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->where('member_id', $member->id)->count());
  }

  public function test_check_out_preserves_attended_status(): void
  {
    $event = $this->publishedEvent();
    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'guest_name' => 'Checked Guest',
      'guest_email' => 'checked-guest@example.com',
      'registration_number' => 'EVT-'.$event->id.'-000010',
      'status' => RegistrationStatus::CheckedIn,
      'consent_accepted' => true,
      'consent_accepted_at' => now(),
      'submitted_at' => now(),
    ]);

    EventCheckIn::query()->create([
      'event_id' => $event->id,
      'registration_id' => $registration->id,
      'method' => 'manual',
      'checked_in_at' => now()->subHours(2),
    ]);

    EventAttendanceHistory::query()->create([
      'event_id' => $event->id,
      'registration_id' => $registration->id,
      'status' => 'present',
      'source' => 'check_in',
      'occurred_at' => now()->subHours(2),
    ]);

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-out")
      ->assertOk();

    $registration->refresh();
    $this->assertSame(RegistrationStatus::Attended, $registration->status);
    $this->assertDatabaseHas('event_attendance_histories', [
      'registration_id' => $registration->id,
      'status' => 'checked_out',
    ]);
  }

  public function test_dynamic_export_includes_configured_fields_and_attendance(): void
  {
    $event = $this->publishedEvent();

    $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
      'settings' => [
        ['field_key' => 'occupation', 'is_enabled' => true, 'show_on_public' => true, 'show_on_quick' => true],
        ['field_key' => 'accommodation_required', 'is_enabled' => true, 'show_on_public' => true],
      ],
    ])->assertOk();

    $questionId = $this->postJson("/api/v1/events/{$event->uuid}/registration-questions", [
      'question' => 'Organization',
      'field_key' => 'organization_custom',
      'answer_type' => 'text',
      'is_required' => false,
      'show_on_public' => true,
    ])->assertCreated()->json('data.question.id');

    $created = $this->postJson('/api/v1/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Export Guest',
        'email' => 'export-guest@example.com',
        'phone' => '+2348022222222',
      ],
      'occupation' => 'Trader',
      'accommodation_required' => true,
      'answers' => [$questionId => 'Lagos Traders Guild'],
      'consent_accepted' => true,
      'check_in_immediately' => true,
    ])->assertCreated();

    $registration = EventRegistration::query()->where('uuid', $created->json('data.registration.id'))->firstOrFail();

    $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-out")->assertOk();

    $headers = RegistrantExportBuilder::headers($event->id);
    $this->assertContains('occupation', $headers);
    $this->assertContains('accommodation_required', $headers);
    $this->assertContains('organization_custom', $headers);
    $this->assertContains('check_in_at', $headers);
    $this->assertContains('check_out_at', $headers);

    $rows = RegistrantExportBuilder::buildRows($event->id, [
      'attendance_status' => 'checked_out',
    ]);

    $this->assertCount(1, $rows);
    $this->assertSame('Trader', $rows[0]['occupation']);
    $this->assertSame('yes', $rows[0]['accommodation_required']);
    $this->assertSame('Lagos Traders Guild', $rows[0]['organization_custom']);
    $this->assertNotEmpty($rows[0]['check_in_at']);
    $this->assertNotEmpty($rows[0]['check_out_at']);
  }

  public function test_phone_identity_reuse_without_duplicate_member_creation(): void
  {
    $eventA = $this->publishedEvent(['title' => 'Event A', 'slug' => 'event-a-'.uniqid()]);
    $eventB = $this->publishedEvent(['title' => 'Event B', 'slug' => 'event-b-'.uniqid()]);

    $member = Member::factory()->create([
      'email' => 'multi-event@example.com',
      'phone' => '+2348033333333',
      'first_name' => 'Multi',
      'last_name' => 'Event',
    ]);

    $this->postJson('/api/v1/events/registrations', [
      'event_id' => $eventA->uuid,
      'registrant' => [
        'name' => 'Multi Event',
        'phone' => '+2348033333333',
        'email' => 'multi-event@example.com',
      ],
      'consent_accepted' => true,
    ])->assertCreated();

    $this->postJson('/api/v1/events/registrations', [
      'event_id' => $eventB->uuid,
      'registrant' => [
        'name' => 'Multi Event',
        'phone' => '+2348033333333',
      ],
      'consent_accepted' => true,
    ])->assertCreated();

    $this->assertSame(2, EventRegistration::query()->where('member_id', $member->id)->count());
    $this->assertSame(1, Member::query()->where('phone', '+2348033333333')->count());
  }
}
