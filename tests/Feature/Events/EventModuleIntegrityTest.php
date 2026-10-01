<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Permission;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAuditLog;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\CheckInTokenService;
use App\Modules\Events\Services\EventDayService;
use App\Modules\Events\Support\EventRegistrationQr;
use App\Modules\Events\Support\EventServicePaymentInstructions;
use App\Services\Iam\AuthorizationService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventModuleIntegrityTest extends IamTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'November 2026 Convergence',
            'slug' => 'november-2026-convergence-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'main_hall_capacity' => 20,
            'overflow_capacity' => 5,
            'seating_policy' => 'main_then_overflow',
        ], $overrides));
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    private function register(Event $event, string $name, array $overrides = []): EventRegistration
    {
        $person = Person::factory()->create([
            'display_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'+'.uniqid().'@example.com',
            'phone' => '+234801'.random_int(1000000, 9999999),
        ]);

        return EventRegistration::query()->create(array_merge([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'status' => RegistrationStatus::Approved,
            'registration_number' => 'EVT-'.$event->id.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'guest_name' => $name,
            'guest_email' => $person->email,
            'guest_phone' => $person->phone,
            'consent_accepted' => true,
            'submitted_at' => now(),
        ], $overrides));
    }

    public function test_public_registration_qr_includes_event_context(): void
    {
        $event = $this->createEvent(['slug' => 'qr-context-event']);

        $this->getJson('/api/v1/events/'.$event->uuid.'/registration-qr')
            ->assertOk()
            ->assertJsonPath('data.kind', EventRegistrationQr::KIND_PUBLIC_REGISTRATION)
            ->assertJsonPath('data.event_id', $event->uuid)
            ->assertJsonPath('data.event_title', $event->title)
            ->assertJsonPath('data.slug', 'qr-context-event')
            ->assertJsonPath('data.publicly_accessible', true);

        $this->assertStringContainsString('/events/qr-context-event', EventRegistrationQr::publicUrl($event));
    }

    public function test_attendee_qr_resolves_and_public_qr_is_rejected_for_check_in(): void
    {
        $event = $this->createEvent();
        $registration = $this->register($event, 'Ada Lovelace');
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $issued['token'],
            'event_id' => $event->uuid,
        ])->assertOk()->assertJsonPath('data.participant.identity.registration_number', $registration->registration_number);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => EventRegistrationQr::publicUrl($event),
            'event_id' => $event->uuid,
        ])->assertStatus(422)->assertJsonFragment(['This is the public event registration QR. Scan the attendee’s personal registration QR to check in.']);
    }

    public function test_check_in_lookup_accepts_legacy_city_timezone_aliases(): void
    {
        $event = $this->createEvent(['timezone' => 'LAGOS']);
        $registration = $this->register($event, 'Timezone Alias');
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $issued['token'],
            'event_id' => $event->uuid,
        ])->assertOk()->assertJsonPath('data.participant.identity.registration_number', $registration->registration_number);
    }

    public function test_qr_for_another_event_is_rejected(): void
    {
        $eventA = $this->createEvent(['title' => 'Event A']);
        $eventB = $this->createEvent(['title' => 'Event B']);
        $registration = $this->register($eventA, 'Grace Hopper');
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $issued['token'],
            'event_id' => $eventB->uuid,
        ])->assertStatus(422)->assertJsonFragment(['This QR code belongs to a different event.']);
    }

    public function test_deleted_registration_qr_cannot_check_in(): void
    {
        $event = $this->createEvent();
        $registration = $this->register($event, 'Katherine Johnson');
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);

        $this->deleteJson('/api/v1/events/registrations/'.$registration->uuid, ['confirm' => true])
            ->assertOk();

        $this->assertDatabaseMissing('event_registrations', ['id' => $registration->id]);
        $this->assertDatabaseHas('persons', ['id' => $registration->person_id]);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $issued['token'],
            'event_id' => $event->uuid,
        ])->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_delete_registration(): void
    {
        $event = $this->createEvent();
        $registration = $this->register($event, 'John Doe');
        $staff = User::factory()->create();
        $staff->permissions()->sync(
            Permission::query()->whereIn('slug', ['registrations.manage', 'registrations.view', 'events.view'])->pluck('id'),
        );
        app(AuthorizationService::class)->clearCacheForUser($staff);
        Sanctum::actingAs($staff);

        $this->deleteJson('/api/v1/events/registrations/'.$registration->uuid, ['confirm' => true])
            ->assertForbidden();

        $this->assertDatabaseHas('event_registrations', ['id' => $registration->id]);
    }

    public function test_sessions_can_be_created_edited_and_deleted_by_uuid(): void
    {
        $event = $this->createEvent();

        $created = $this->postJson('/api/v1/events/'.$event->uuid.'/sessions', [
            'title' => 'Opening session',
            'starts_at' => now()->setTime(9, 0)->toIso8601String(),
            'ends_at' => now()->setTime(11, 0)->toIso8601String(),
            'track' => 'Main',
            'room' => 'Hall A',
            'capacity' => 200,
            'grace_before_minutes' => 10,
            'grace_after_minutes' => 15,
            'is_active' => true,
        ])->assertCreated();

        $sessionId = $created->json('data.session.id');
        $this->assertNotNull($sessionId);

        $this->putJson('/api/v1/events/sessions/'.$sessionId, [
            'title' => 'Opening session updated',
            'starts_at' => now()->setTime(9, 30)->toIso8601String(),
            'ends_at' => now()->setTime(11, 30)->toIso8601String(),
            'track' => 'Plenary',
            'room' => 'Main Hall',
            'capacity' => 250,
            'session_number' => 1,
            'is_active' => true,
            'grace_before_minutes' => 5,
            'grace_after_minutes' => 20,
        ])->assertOk()->assertJsonPath('data.session.title', 'Opening session updated');

        $this->deleteJson('/api/v1/events/sessions/'.$sessionId)->assertOk();
        $this->assertSoftDeleted('event_sessions', ['uuid' => $sessionId]);
    }

    public function test_session_with_attendance_is_deactivated_instead_of_deleted(): void
    {
        $event = $this->createEvent();
        $session = EventSession::query()->create([
            'event_id' => $event->id,
            'title' => 'Session with attendance',
            'session_number' => 1,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(11, 0),
            'is_active' => true,
        ]);
        $registration = $this->register($event, 'Attendee One');
        EventSessionAttendance::query()->create([
            'event_id' => $event->id,
            'event_session_id' => $session->id,
            'registration_id' => $registration->id,
            'person_id' => $registration->person_id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ]);

        $this->deleteJson('/api/v1/events/sessions/'.$session->uuid)
            ->assertStatus(422);

        $this->deleteJson('/api/v1/events/sessions/'.$session->uuid.'?deactivate=1')
            ->assertOk();

        $this->assertDatabaseHas('event_sessions', ['id' => $session->id, 'is_active' => 0]);
        $this->assertDatabaseHas('event_session_attendances', ['event_session_id' => $session->id]);
    }

    public function test_reset_and_undo_registration_data_is_transactional_and_audited(): void
    {
        $event = $this->createEvent();
        $registration = $this->register($event, 'Test Registrant', [
            'accommodation_required' => true,
            'airport_pickup_required' => true,
        ]);
        EventRegService::query()->create([
            'registration_id' => $registration->id,
            'type' => 'accommodation',
            'status' => 'requested',
        ]);
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);
        $personId = $registration->person_id;
        $registrationNumber = $registration->registration_number;

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-data/reset', [
            'confirm' => true,
            'confirmation' => 'RESET REGISTRATIONS',
        ])->assertOk();

        $this->assertDatabaseMissing('event_registrations', ['id' => $registration->id]);
        $this->assertDatabaseHas('persons', ['id' => $personId]);
        $this->assertSame(1, EventAuditLog::query()->where('event_id', $event->id)->where('event_type', EventAuditEventType::RegistrationsReset->value)->count());

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-data/undo', [
            'confirm' => true,
            'confirmation' => 'UNDO RESET',
        ])->assertOk();

        $this->assertDatabaseHas('event_registrations', ['registration_number' => $registrationNumber]);
        $this->assertSame(1, EventAuditLog::query()->where('event_id', $event->id)->where('event_type', EventAuditEventType::RegistrationsRestored->value)->count());

        $restored = EventRegistration::query()->where('registration_number', $registrationNumber)->first();
        $this->assertNotNull($restored);
        $this->assertTrue(EventCheckInToken::query()->where('registration_id', $restored->id)->where('token_hash', hash('sha256', $issued['token']))->exists());
        $this->assertTrue(EventRegService::query()->where('registration_id', $restored->id)->where('type', 'accommodation')->exists());
    }

    public function test_undo_reset_refuses_to_overwrite_newer_registrations(): void
    {
        $event = $this->createEvent();
        $original = $this->register($event, 'Original Registrant');
        $originalNumber = $original->registration_number;

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-data/reset', [
            'confirm' => true,
            'confirmation' => 'RESET REGISTRATIONS',
        ])->assertOk();

        $newer = $this->register($event, 'Newer Registrant');

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-data/undo', [
            'confirm' => true,
            'confirmation' => 'UNDO RESET',
        ])->assertStatus(422)->assertJsonFragment([
            'Undo is blocked because new registrations exist after the reset. Delete them first or keep the reset.',
        ]);

        $this->assertDatabaseMissing('event_registrations', ['registration_number' => $originalNumber]);
        $this->assertDatabaseHas('event_registrations', ['id' => $newer->id]);
        $this->assertSame(
            0,
            EventAuditLog::query()->where('event_id', $event->id)->where('event_type', EventAuditEventType::RegistrationsRestored->value)->count(),
        );
    }

    public function test_reset_without_confirmation_is_rejected_and_form_config_reset_is_separate(): void
    {
        $event = $this->createEvent();
        $this->register($event, 'Keep Me');

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-data/reset', [
            'confirm' => true,
            'confirmation' => 'WRONG',
        ])->assertStatus(422);

        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id]);

        $this->postJson('/api/v1/events/'.$event->uuid.'/registration-form/reset', [
            'confirm' => true,
            'confirmation' => 'RESET FORM CONFIGURATION',
        ])->assertOk();

        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id]);
        $this->assertSame(1, EventAuditLog::query()->where('event_id', $event->id)->where('event_type', EventAuditEventType::FormConfigurationReset->value)->count());
    }

    public function test_payment_instructions_are_conditional(): void
    {
        $event = $this->createEvent();
        $neither = $this->register($event, 'Neither Person');
        $this->assertNull(EventServicePaymentInstructions::forRegistration($neither));

        $lodging = $this->register($event, 'Stay Person', ['accommodation_required' => true]);
        $lodgingPayload = EventServicePaymentInstructions::forRegistration($lodging);
        $this->assertNotNull($lodgingPayload);
        $this->assertSame('Luvanex International Limited', $lodgingPayload['account_name']);
        $this->assertSame('1022860128', $lodgingPayload['account_number']);
        $this->assertSame('Joy', $lodgingPayload['whatsapp_contacts'][0]['name']);
        $this->assertSame('Kenny', $lodgingPayload['whatsapp_contacts'][1]['name']);

        $transport = $this->register($event, 'Ride Person', ['airport_pickup_required' => true]);
        $this->assertNotNull(EventServicePaymentInstructions::forRegistration($transport));

        $both = $this->register($event, 'Both Person', [
            'accommodation_required' => true,
            'airport_pickup_required' => true,
        ]);
        $bothPayload = EventServicePaymentInstructions::forRegistration($both);
        $this->assertSame('accommodation_and_transport', $bothPayload['scope']);
    }

    public function test_invalid_qr_is_rejected(): void
    {
        $event = $this->createEvent();
        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => 'NOT-A-REAL-TOKEN',
            'event_id' => $event->uuid,
        ])->assertStatus(422);
    }

    public function test_already_checked_in_lookup_still_identifies_attendee(): void
    {
        $event = $this->createEvent();
        $registration = $this->register($event, 'Repeat Scan');
        $issued = app(CheckInTokenService::class)->issue($registration, null, $this->admin);
        app(AttendanceService::class)->checkIn($registration, ['force' => true], $this->admin);

        $this->postJson('/api/v1/events/check-in/lookup', [
            'token' => $issued['token'],
            'event_id' => $event->uuid,
        ])->assertOk();
    }
}
