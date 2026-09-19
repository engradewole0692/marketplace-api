<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Permission;
use App\Models\Person;
use App\Models\User;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Events\Enums\EventStaffRole;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventSeatClassification;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Services\CheckInTokenService;
use App\Modules\Events\Services\EventDayService;
use App\Services\Iam\AuthorizationService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPhase6SessionsSeatingTest extends IamTestCase
{
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'Phase 6 Convergence',
            'slug' => 'phase6-convergence-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(2)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 800,
            'main_hall_capacity' => 2,
            'overflow_capacity' => 1,
            'seating_policy' => 'main_then_overflow',
            'currency' => 'NGN',
            'accommodation_enabled' => true,
        ], $overrides));
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    /**
     * @return list<EventSession>
     */
    private function configureFiveSessions(Event $event): array
    {
        $day = now()->startOfDay();
        $specs = [
            [1, '09:00', '11:00'],
            [2, '13:00', '15:00'],
            [3, '09:00', '11:00'],
            [4, '13:00', '15:00'],
            [5, '09:00', '11:00'],
        ];
        $sessions = [];
        foreach ($specs as $index => [$number, $start, $end]) {
            $date = $day->copy()->addDays((int) floor($index / 2));
            $sessions[] = EventSession::query()->create([
                'event_id' => $event->id,
                'title' => 'Session '.$number,
                'session_number' => $number,
                'sort_order' => $number,
                'starts_at' => $date->copy()->setTimeFromTimeString($start),
                'ends_at' => $date->copy()->setTimeFromTimeString($end),
                'is_active' => true,
                'grace_before_minutes' => 15,
                'grace_after_minutes' => 15,
            ]);
        }

        return $sessions;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function userWithPermissions(array $slugs): User
    {
        $user = User::factory()->create();
        $user->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
        app(AuthorizationService::class)->clearCacheForUser($user);

        return $user;
    }

    private function registerGuest(Event $event, string $name, string $email, string $phone, array $extra = []): EventRegistration
    {
        $payload = array_merge([
            'event_id' => $event->uuid,
            'registrant' => ['name' => $name, 'email' => $email, 'phone' => $phone, 'phone_country_code' => 'NG'],
            'consent_accepted' => true,
        ], $extra);

        $this->postJson('/api/v1/public/events/registrations', $payload)->assertSuccessful();

        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereHas('person', fn ($q) => $q->where('email', strtolower($email)))
            ->firstOrFail();
    }

    public function test_existing_person_reused_across_events_without_duplicate_registration(): void
    {
        $eventA = $this->createEvent(['title' => 'Event A', 'slug' => 'event-a-'.uniqid()]);
        $eventB = $this->createEvent(['title' => 'Event B', 'slug' => 'event-b-'.uniqid()]);

        $first = $this->registerGuest($eventA, 'John Ade', 'john.ade@example.com', '+2348011111111');
        $second = $this->registerGuest($eventB, 'John Ade', 'john.ade@example.com', '+2348011111111');
        $again = $this->registerGuest($eventA, 'John Ade', 'john.ade@example.com', '+2348011111111');

        $this->assertSame($first->person_id, $second->person_id);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Person::query()->where('email', 'john.ade@example.com')->count());
        $this->assertSame(2, EventRegistration::query()->where('person_id', $first->person_id)->count());
    }

    public function test_planned_sessions_are_stored_and_not_attendance(): void
    {
        $event = $this->createEvent();
        $sessions = $this->configureFiveSessions($event);
        $chosen = [$sessions[0]->uuid, $sessions[2]->uuid, $sessions[4]->uuid];

        $registration = $this->registerGuest(
            $event,
            'Session Planner',
            'planner@example.com',
            '+2348012222222',
            ['planned_session_ids' => $chosen],
        );

        $this->assertCount(3, $registration->plannedSessions()->get());
        $this->assertSame(0, EventSessionAttendance::query()->where('registration_id', $registration->id)->count());
    }

    public function test_automatic_session_detection_and_independent_days(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(9, 37));
        $event = $this->createEvent();
        $sessions = $this->configureFiveSessions($event);
        $guest = $this->registerGuest($event, 'Auto Session', 'auto.session@example.com', '+2348013333333');
        $token = app(CheckInTokenService::class)->issue($guest, null, $this->admin)['token'];

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
        ])->assertCreated();

        $this->assertDatabaseHas('event_session_attendances', [
            'registration_id' => $guest->id,
            'event_session_id' => $sessions[0]->id,
            'status' => 'checked_in',
            'seating_area' => 'main_hall',
        ]);

        Carbon::setTestNow(now()->startOfDay()->setTime(13, 43));
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
        ])->assertCreated();

        $this->assertSame(2, EventSessionAttendance::query()->where('registration_id', $guest->id)->count());
        $this->assertDatabaseHas('event_session_attendances', [
            'registration_id' => $guest->id,
            'event_session_id' => $sessions[1]->id,
            'status' => 'checked_in',
        ]);

        Carbon::setTestNow();
    }

    public function test_ambiguous_session_requires_explicit_selection(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(11, 30));
        $event = $this->createEvent();
        $sessions = $this->configureFiveSessions($event);
        $guest = $this->registerGuest($event, 'Gap Time', 'gap.time@example.com', '+2348014444444');
        $token = app(CheckInTokenService::class)->issue($guest, null, $this->admin)['token'];

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
        ])->assertStatus(409)->assertJsonFragment(['message' => 'Multiple/no active sessions detected.']);

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_session_id' => $sessions[0]->uuid,
        ])->assertCreated();

        Carbon::setTestNow();
    }

    public function test_capacity_overflow_and_non_seat_staff(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(9, 20));
        $event = $this->createEvent();
        $this->configureFiveSessions($event);
        $this->putJson("/api/v1/events/{$event->uuid}/seat-classifications", [
            'classifications' => [
                [
                    'scope' => 'staff_department',
                    'match_key' => 'media',
                    'label' => 'Media',
                    'counts_toward_seating' => false,
                ],
            ],
        ])->assertOk();

        $a = $this->registerGuest($event, 'Seat A', 'seat.a@example.com', '+2348015550001');
        $b = $this->registerGuest($event, 'Seat B', 'seat.b@example.com', '+2348015550002');
        $c = $this->registerGuest($event, 'Seat C', 'seat.c@example.com', '+2348015550003');
        $d = $this->registerGuest($event, 'Seat D', 'seat.d@example.com', '+2348015550004');

        $tokenA = app(CheckInTokenService::class)->issue($a, null, $this->admin)['token'];
        $tokenB = app(CheckInTokenService::class)->issue($b, null, $this->admin)['token'];
        $tokenC = app(CheckInTokenService::class)->issue($c, null, $this->admin)['token'];
        $tokenD = app(CheckInTokenService::class)->issue($d, null, $this->admin)['token'];

        $this->postJson('/api/v1/events/check-in/scan', ['token' => $tokenA, 'event_id' => $event->uuid])->assertCreated();
        $this->postJson('/api/v1/events/check-in/scan', ['token' => $tokenB, 'event_id' => $event->uuid])->assertCreated();
        $overflow = $this->postJson('/api/v1/events/check-in/scan', ['token' => $tokenC, 'event_id' => $event->uuid]);
        $overflow->assertCreated();
        $this->assertSame('overflow', $overflow->json('data.check_in.seating_area'));

        $full = $this->postJson('/api/v1/events/check-in/scan', ['token' => $tokenD, 'event_id' => $event->uuid]);
        $full->assertStatus(409);

        $mediaUser = $this->userWithPermissions(['events.staff', 'events.view', 'registrations.view', 'attendance.manage']);
        $this->postJson("/api/v1/events/{$event->uuid}/staff", [
            'user_id' => $mediaUser->uuid,
            'staff_role' => EventStaffRole::Staff->value,
            'department' => 'media',
        ])->assertSuccessful();

        $mediaPerson = Person::query()->create([
            'person_no' => Person::nextTemporaryNumber(),
            'first_name' => 'Cam',
            'last_name' => 'Crew',
            'email' => 'media.crew@example.com',
            'phone' => '+2348015550099',
            'user_id' => $mediaUser->id,
        ]);
        $mediaReg = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'person_id' => $mediaPerson->uuid,
            'consent_accepted' => true,
            'check_in_immediately' => false,
        ])->assertSuccessful()->json('data.registration.id');

        $mediaModel = EventRegistration::query()->where('uuid', $mediaReg)->firstOrFail();
        $tokenMedia = app(CheckInTokenService::class)->issue($mediaModel, null, $this->admin)['token'];
        $mediaCheck = $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $tokenMedia,
            'event_id' => $event->uuid,
        ])->assertCreated();
        $this->assertSame('none', $mediaCheck->json('data.check_in.seating_area'));
        $this->assertFalse((bool) $mediaCheck->json('data.check_in.counts_toward_seating'));

        $ops = $this->getJson("/api/v1/events/{$event->uuid}/ops-dashboard")->assertOk();
        $this->assertSame(2, $ops->json('data.seating.main_hall.occupied'));
        $this->assertSame(1, $ops->json('data.seating.overflow.occupied'));
        $this->assertSame(0, $ops->json('data.seating.total.available'));

        Carbon::setTestNow();
    }

    public function test_capacity_override_is_restricted_and_audited(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(9, 20));
        $event = $this->createEvent(['main_hall_capacity' => 1, 'overflow_capacity' => 0]);
        $this->configureFiveSessions($event);
        $first = $this->registerGuest($event, 'Only Seat', 'only.seat@example.com', '+2348016660001');
        $second = $this->registerGuest($event, 'Override Me', 'override.me@example.com', '+2348016660002');
        $tokenA = app(CheckInTokenService::class)->issue($first, null, $this->admin)['token'];
        $tokenB = app(CheckInTokenService::class)->issue($second, null, $this->admin)['token'];
        $this->postJson('/api/v1/events/check-in/scan', ['token' => $tokenA, 'event_id' => $event->uuid])->assertCreated();

        $staff = $this->userWithPermissions(['events.staff', 'events.view', 'registrations.view', 'attendance.manage']);
        \App\Modules\Events\Models\EventStaffAssignment::query()->create([
            'event_id' => $event->id,
            'user_id' => $staff->id,
            'staff_role' => EventStaffRole::Staff->value,
            'is_active' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $tokenB,
            'event_id' => $event->uuid,
            'capacity_override' => true,
            'override_reason' => 'Door exception',
        ])->assertStatus(409);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $tokenB,
            'event_id' => $event->uuid,
            'capacity_override' => true,
            'override_reason' => 'Administrator override',
        ])->assertCreated();

        Carbon::setTestNow();
    }

    public function test_shared_occupants_do_not_create_persons(): void
    {
        $event = $this->createEvent();
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Shared 4',
            'occupancy_type' => 'shared',
            'capacity' => 4,
            'unit_count' => 1,
            'price' => 50000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $before = Person::query()->count();
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Primary Payer',
                'email' => 'primary.payer@example.com',
                'phone' => '+2348017770001',
                'phone_country_code' => 'NG',
            ],
            'occupation' => 'Pastor',
            'consent_accepted' => true,
            'accommodation' => [
                'option_id' => $option['id'],
                'occupancy_type' => 'shared',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
                'occupant_count' => 4,
                'occupants' => [
                    ['name' => 'Guest One', 'gender' => 'female', 'country' => 'GH', 'state_region' => 'Greater Accra'],
                    ['name' => 'Guest Two', 'gender' => 'male', 'country' => 'NG', 'state_region' => 'Lagos'],
                    ['name' => 'Guest Three', 'gender' => 'female', 'country' => 'GH', 'state_region' => 'GH-AA'],
                ],
            ],
        ])->assertSuccessful();

        $this->assertSame($before + 1, Person::query()->count());
        $registration = EventRegistration::query()->whereHas('person', fn ($q) => $q->where('email', 'primary.payer@example.com'))->firstOrFail();
        $details = $registration->services()->where('type', 'accommodation')->first()?->details ?? [];
        $this->assertCount(3, $details['occupants'] ?? []);
        $this->assertSame('GH', $details['occupants'][0]['country'] ?? null);
        $this->assertSame('Greater Accra', $details['occupants'][0]['state_region'] ?? null);
        $this->assertSame(2, $details['nights'] ?? null);
    }

    public function test_sms_without_provider_does_not_claim_delivery(): void
    {
        $row = app(OutboundMessageService::class)->send('sms', '+2348018880001', 'Test SMS', [], $this->admin);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error_message);
        $this->assertDatabaseHas('communication_outbound_messages', [
            'id' => $row->id,
            'channel' => 'sms',
            'status' => 'failed',
        ]);
        $this->assertSame(0, CommunicationOutboundMessage::query()->where('status', 'sent')->where('channel', 'sms')->count());
    }

    public function test_quick_registration_existing_person_immediate_check_in(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(9, 15));
        $event = $this->createEvent();
        $this->configureFiveSessions($event);
        $existing = $this->registerGuest($event, 'On Site', 'onsite.existing@example.com', '+2348019990001');
        $eventB = $this->createEvent(['title' => 'Other', 'slug' => 'other-'.uniqid()]);

        $response = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $eventB->uuid,
            'person_id' => $existing->person->uuid,
            'consent_accepted' => true,
            'check_in_immediately' => true,
        ]);
        $response->assertSuccessful();
        $this->assertSame($existing->person_id, EventRegistration::query()->where('uuid', $response->json('data.registration.id'))->value('person_id'));

        Carbon::setTestNow();
    }
}
