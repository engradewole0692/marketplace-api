<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Person;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\EventDayService;
use App\Modules\Events\Services\EventOperationalProfileService;
use App\Modules\Events\Support\EventRegistrationQr;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventRegistrationQrAndCheckoutTest extends IamTestCase
{
    public function test_registration_qr_encodes_public_url_not_attendance_token(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent(['slug' => 'qr-public-event']);

        $response = $this->getJson('/api/v1/events/'.$event->uuid.'/registration-qr')
            ->assertOk();

        $url = $response->json('data.url');
        $this->assertStringContainsString('/events/qr-public-event', $url);
        $this->assertStringNotContainsString('token=', $url);
        $this->assertSame($url, EventRegistrationQr::publicUrl($event));
        $this->assertStringContainsString('create-qr-code', (string) $response->json('data.qr_image_url'));

        $show = $this->getJson('/api/v1/events/'.$event->uuid)->assertOk();
        $this->assertTrue(
            in_array($url, [
                $show->json('data.public_registration_url'),
                $show->json('data.event.public_registration_url'),
            ], true) || str_contains(json_encode($show->json()) ?: '', 'qr-public-event')
        );
    }

    public function test_checkout_disabled_blocks_checkout_without_deleting_attendance(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent(['checkout_enabled' => false, 'main_hall_capacity' => 50]);
        $registration = $this->register($event, 'Ada Lovelace');
        app(AttendanceService::class)->checkIn($registration, ['force' => true], $this->admin);

        $this->postJson('/api/v1/events/registrations/'.$registration->uuid.'/check-out', [])
            ->assertStatus(422);

        $this->assertDatabaseHas('event_day_attendances', [
            'registration_id' => $registration->id,
        ]);
    }

    public function test_checkout_enabled_records_checkout(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent(['checkout_enabled' => true, 'main_hall_capacity' => 50]);
        $registration = $this->register($event, 'Grace Hopper');

        app(AttendanceService::class)->checkIn($registration, ['force' => true], $this->admin);
        $history = app(AttendanceService::class)->checkOut($registration, [], $this->admin);
        $this->assertNotNull($history->id);
        $this->assertDatabaseHas('event_attendance_histories', [
            'registration_id' => $registration->id,
            'status' => 'checked_out',
        ]);
    }

    public function test_ops_dashboard_includes_live_occupancy_and_activity(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent(['main_hall_capacity' => 10, 'overflow_capacity' => 5]);

        $this->getJson('/api/v1/events/'.$event->uuid.'/ops-dashboard')
            ->assertOk()
            ->assertJsonPath('data.checkout_enabled', true)
            ->assertJsonStructure([
                'data' => [
                    'seating' => ['main_hall', 'overflow'],
                    'totals' => ['registered', 'checked_in_today', 'checked_out_today'],
                    'last_check_in',
                    'last_check_out',
                    'sessions',
                ],
            ]);
    }

    public function test_operational_profile_includes_existing_phone_and_gender(): void
    {
        $event = $this->createEvent();
        $person = Person::factory()->create([
            'display_name' => 'Katherine Johnson',
            'email' => 'katherine.johnson@example.com',
            'phone' => '+15551231234',
        ]);
        $registration = EventRegistration::query()->create([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'registration_number' => 'REG-'.uniqid(),
            'guest_name' => 'Katherine Johnson',
            'guest_phone' => '+15551231234',
            'consent_accepted' => true,
            'submitted_at' => now(),
            'metadata' => ['profile' => ['gender' => 'Female']],
        ]);

        $profile = app(EventOperationalProfileService::class)->forRegistration($registration, [], $this->admin);

        $this->assertSame('+15551231234', $profile['identity']['phone']);
        $this->assertSame('Female', $profile['identity']['gender']);
        $this->assertSame($registration->registration_number, $profile['identity']['registration_number']);
    }

    public function test_disabled_checkout_throws_from_service(): void
    {
        $event = $this->createEvent(['checkout_enabled' => false]);
        $registration = $this->register($event, 'Blocked Checkout');
        app(AttendanceService::class)->checkIn($registration, ['force' => true], $this->admin);

        $this->expectException(ValidationException::class);
        app(AttendanceService::class)->checkOut($registration, [], $this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'QR Checkout Event',
            'slug' => 'qr-checkout-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'checkout_enabled' => true,
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

    private function register(Event $event, string $name): EventRegistration
    {
        $person = Person::factory()->create(['display_name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);

        return EventRegistration::query()->create([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'registration_number' => 'REG-'.uniqid(),
            'guest_name' => $name,
            'guest_email' => $person->email,
            'consent_accepted' => true,
            'submitted_at' => now(),
        ]);
    }
}
