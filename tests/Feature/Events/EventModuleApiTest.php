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
}
