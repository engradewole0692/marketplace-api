<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Permission;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\EventStaffRole;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;
use App\Modules\Events\Models\EventStaffAssignment;
use App\Modules\Events\Services\CheckInTokenService;
use App\Modules\Events\Services\EventDayService;
use App\Services\Iam\AuthorizationService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPhase5OpsTest extends IamTestCase
{
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'Phase 5 Conference',
            'slug' => 'phase5-conference-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(2)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 200,
        ], $overrides));
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function userWithPermissions(array $slugs): User
    {
        $user = User::factory()->create();
        $user->permissions()->sync(
            Permission::query()->whereIn('slug', $slugs)->pluck('id'),
        );
        app(AuthorizationService::class)->clearCacheForUser($user);

        return $user;
    }

    private function assign(Event $event, User $user, string $role = 'staff'): EventStaffAssignment
    {
        return EventStaffAssignment::query()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'staff_role' => $role,
            'is_active' => true,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function officer(Event $event, EventStaffRole $role): User
    {
        $user = $this->userWithPermissions(['events.staff', 'events.view', 'registrations.view']);
        $this->assign($event, $user, $role->value);

        return $user;
    }

    private function registerGuest(Event $event, string $name, string $email, string $phone): EventRegistration
    {
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => $name, 'email' => $email, 'phone' => $phone],
            'consent_accepted' => true,
        ])->assertSuccessful();

        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereHas('person', fn ($q) => $q->where('email', strtolower($email)))
            ->firstOrFail();
    }

    private function issueToken(EventRegistration $registration): string
    {
        return $this->postJson("/api/v1/events/registrations/{$registration->uuid}/check-in-token")
            ->assertCreated()
            ->json('data.token');
    }

    public function test_valid_qr_check_in_and_lookup_does_not_check_in(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Ada Check', 'ada.phase5@example.com', '+2348015000001');
        $token = $this->issueToken($guest);
        $day = $event->days->first();

        $lookup = $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertOk();

        $this->assertSame('Ada Check', $lookup->json('data.participant.identity.name'));
        $this->assertSame($guest->registration_number, $lookup->json('data.participant.identity.registration_number'));
        $this->assertTrue($lookup->json('data.participant.attendance.can_check_in'));
        $this->assertDatabaseMissing('event_day_attendances', [
            'registration_id' => $guest->id,
            'status' => 'checked_in',
        ]);

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertCreated();

        $this->assertDatabaseHas('event_day_attendances', [
            'registration_id' => $guest->id,
            'event_day_id' => $day->id,
            'status' => 'checked_in',
        ]);
        $this->assertDatabaseHas('event_registration_audit_logs', [
            'registration_id' => $guest->id,
            'event_type' => RegistrationAuditEventType::CheckInRecorded->value,
        ]);
        $this->assertDatabaseHas('event_registration_audit_logs', [
            'registration_id' => $guest->id,
            'event_type' => RegistrationAuditEventType::QrTokenScanned->value,
        ]);
    }

    public function test_invalid_qr_is_rejected(): void
    {
        $this->createEvent();
        $this->postJson('/api/v1/events/check-in/scan', ['token' => 'NOTAREALTOKEN1234567890'])
            ->assertStatus(422);
    }

    public function test_qr_for_another_event_is_rejected(): void
    {
        $eventA = $this->createEvent(['title' => 'Alpha Ops', 'slug' => 'alpha-ops-'.uniqid()]);
        $eventB = $this->createEvent(['title' => 'Beta Ops', 'slug' => 'beta-ops-'.uniqid()]);
        $guest = $this->registerGuest($eventA, 'Cross Event', 'cross.phase5@example.com', '+2348015000002');
        $token = $this->issueToken($guest);

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $eventB->uuid,
        ])->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This QR code belongs to a different event.');
    }

    public function test_duplicate_check_in_is_rejected_with_already_message(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Dup In', 'dupin.phase5@example.com', '+2348015000003');
        $token = $this->issueToken($guest);
        $day = $event->days->first();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertCreated();

        $second = $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertStatus(422);

        $this->assertStringContainsString('Already checked in', (string) $second->json('errors.registration.0'));
    }

    public function test_check_out_and_duplicate_check_out(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Out Guest', 'out.phase5@example.com', '+2348015000004');
        $token = $this->issueToken($guest);
        $day = $event->days->first();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertCreated();

        $this->postJson('/api/v1/events/check-out/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertOk();

        $this->assertDatabaseHas('event_day_attendances', [
            'registration_id' => $guest->id,
            'event_day_id' => $day->id,
            'status' => 'checked_out',
        ]);

        $dup = $this->postJson('/api/v1/events/check-out/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day->uuid,
        ])->assertStatus(422);
        $this->assertStringContainsString('Already checked out', (string) $dup->json('errors.registration.0'));
    }

    public function test_daily_attendance_is_independent_across_days(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Daily Guest', 'daily.phase5@example.com', '+2348015000005');
        $token = $this->issueToken($guest);
        $day1 = $event->days->firstWhere('day_index', 1);
        $day2 = $event->days->firstWhere('day_index', 2);
        $this->assertNotNull($day1);
        $this->assertNotNull($day2);

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day1->uuid,
        ])->assertCreated();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $day2->uuid,
        ])->assertCreated();

        $this->assertSame('checked_in', EventDayAttendance::query()->where('registration_id', $guest->id)->where('event_day_id', $day1->id)->value('status')?->value
            ?? EventDayAttendance::query()->where('registration_id', $guest->id)->where('event_day_id', $day1->id)->value('status'));
        $this->assertSame('checked_in', EventDayAttendance::query()->where('registration_id', $guest->id)->where('event_day_id', $day2->id)->value('status')?->value
            ?? EventDayAttendance::query()->where('registration_id', $guest->id)->where('event_day_id', $day2->id)->value('status'));
        $this->assertSame(1, EventDayAttendance::query()->where('registration_id', $guest->id)->where('event_day_id', $day1->id)->count());
    }

    public function test_wrong_event_day_is_rejected(): void
    {
        $eventA = $this->createEvent(['title' => 'Day Alpha', 'slug' => 'day-alpha-'.uniqid()]);
        $eventB = $this->createEvent(['title' => 'Day Beta', 'slug' => 'day-beta-'.uniqid()]);
        $guest = $this->registerGuest($eventA, 'Wrong Day', 'wrongday.phase5@example.com', '+2348015000006');
        $token = $this->issueToken($guest);

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $eventA->uuid,
            'event_day_id' => $eventB->days->first()->uuid,
        ])->assertStatus(422);
    }

    public function test_unauthorized_staff_cannot_scan(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'No Access', 'noaccess.phase5@example.com', '+2348015000007');
        $token = $this->issueToken($guest);
        $staff = $this->userWithPermissions(['events.staff', 'events.view', 'registrations.view']);

        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
        ])->assertForbidden();
    }

    public function test_event_administrator_can_check_in_without_global_attendance_permission(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Admin Ops', 'adminops.phase5@example.com', '+2348015000008');
        $token = $this->issueToken($guest);
        $admin = $this->officer($event, EventStaffRole::EventAdministrator);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $event->days->first()->uuid,
        ])->assertCreated();
    }

    public function test_officer_role_restrictions(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Officer Guest', 'officer.phase5@example.com', '+2348015000009');
        $acc = $this->officer($event, EventStaffRole::AccommodationOfficer);
        $log = $this->officer($event, EventStaffRole::LogisticsOfficer);
        $travel = $this->officer($event, EventStaffRole::TravelOfficer);
        $finance = $this->officer($event, EventStaffRole::FinanceOfficer);

        Sanctum::actingAs($acc);
        $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Staff Lodge',
            'occupancy_type' => 'private',
            'price' => 10000,
            'currency' => 'NGN',
        ])->assertCreated();
        $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Should Fail',
            'price' => 1000,
        ])->assertForbidden();

        Sanctum::actingAs($log);
        $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Airport Shuttle',
            'price' => 5000,
            'currency' => 'NGN',
        ])->assertCreated();
        $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Should Fail Lodge',
            'price' => 1000,
        ])->assertForbidden();

        Sanctum::actingAs($travel);
        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/travel", [
            'origin' => 'Lagos',
            'destination' => 'Abuja',
        ])->assertSuccessful();
        $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Travel Should Fail',
            'price' => 1000,
        ])->assertForbidden();

        Sanctum::actingAs($finance);
        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/payments/approve", [
            'notes' => 'Verified',
        ])->assertSuccessful();
        $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Finance Fail',
            'price' => 1000,
        ])->assertForbidden();
    }

    public function test_quick_registration_check_in_reuses_person_and_prevents_duplicates(): void
    {
        $event = $this->createEvent();
        $email = 'reuse.phase5@example.com';
        $existing = $this->registerGuest($event, 'Reuse Person', $email, '+2348015000010');
        $personCount = Person::query()->where('email', $email)->count();

        $created = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Reuse Person', 'email' => $email, 'phone' => '+2348015000010'],
            'consent_accepted' => true,
            'check_in_immediately' => true,
            'event_day_id' => $event->days->first()->uuid,
        ])->assertSuccessful();

        $this->assertSame($existing->uuid, $created->json('data.registration.id'));
        $this->assertSame($personCount, Person::query()->where('email', $email)->count());
        $this->assertDatabaseHas('event_day_attendances', [
            'registration_id' => $existing->id,
            'status' => 'checked_in',
        ]);
    }

    public function test_operational_reports_and_membership_presentation(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Report Guest', 'report.phase5@example.com', '+2348015000011');
        $token = $this->issueToken($guest);
        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $event->days->first()->uuid,
        ])->assertCreated();

        $matrix = $this->getJson("/api/v1/events/{$event->uuid}/attendance-report?membership=visitor")->assertOk();
        $this->assertSame('visitor', $matrix->json('data.rows.0.membership_presentation'));
        $this->assertSame('Visitor', $matrix->json('data.rows.0.membership'));
        $dayId = $event->days->first()->uuid;
        $this->assertSame('checked_in', $matrix->json("data.rows.0.days.{$dayId}.status"));

        $ops = $this->getJson("/api/v1/events/{$event->uuid}/ops-dashboard")->assertOk();
        $this->assertSame(1, $ops->json('data.totals.currently_present'));

        $this->postJson('/api/v1/events/reports', [
            'event_id' => $event->uuid,
            'report_type' => 'attendance',
        ])->assertSuccessful();
        $this->postJson('/api/v1/events/reports', [
            'event_id' => $event->uuid,
            'report_type' => 'accommodation',
        ])->assertSuccessful();
        $this->postJson('/api/v1/events/reports', [
            'event_id' => $event->uuid,
            'report_type' => 'logistics',
        ])->assertSuccessful();
        $this->postJson('/api/v1/events/reports', [
            'event_id' => $event->uuid,
            'report_type' => 'travel',
        ])->assertSuccessful();

        $this->assertGreaterThan(
            0,
            EventRegistrationAuditLog::query()->where('registration_id', $guest->id)->count(),
        );
        $this->assertNotSame(RegistrationStatus::Cancelled, $guest->fresh()->status);
    }

    public function test_token_reveal_does_not_rotate_existing_qr(): void
    {
        $event = $this->createEvent();
        $guest = $this->registerGuest($event, 'Reveal Guest', 'reveal.phase5@example.com', '+2348015000012');
        $first = app(CheckInTokenService::class)->issue($guest);
        $second = app(CheckInTokenService::class)->reveal($guest);

        $this->assertFalse($second['rotated']);
        $this->assertSame($first['token'], $second['token']);
    }
}
