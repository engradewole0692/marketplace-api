<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Services\EventDayService;
use App\Modules\Events\Services\RegistrationExportGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Iam\IamTestCase;

final class EventPhase4ServicesTest extends IamTestCase
{
    private function unpublishedFlagsOff(): Event
    {
        $event = Event::query()->create([
            'title' => 'Phase 4 Conference',
            'slug' => 'phase4-conference-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(5)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 200,
            'currency' => 'NGN',
            'accommodation_enabled' => false,
            'transport_enabled' => false,
            'travel_assistance_enabled' => false,
        ]);
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
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

    public function test_admin_option_appears_on_public_form_without_preenabled_flag(): void
    {
        $event = $this->unpublishedFlagsOff();
        $this->assertFalse((bool) $event->accommodation_enabled);
        $this->assertFalse((bool) $event->transport_enabled);

        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Executive Apartment',
            'location' => 'Victoria Island',
            'occupancy_type' => 'shared',
            'capacity' => 4,
            'unit_count' => 2,
            'price' => 200000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $route = $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Airport to Hotel',
            'route' => 'Airport → Hotel',
            'origin' => 'Airport',
            'destination' => 'Hotel',
            'price' => 30000,
            'price_basis' => 'per_trip',
            'currency' => 'NGN',
            'vehicle_name' => 'Toyota Hiace',
            'passenger_capacity' => 14,
        ])->assertCreated()->json('data.option');

        $event->refresh();
        $this->assertTrue((bool) $event->accommodation_enabled);
        $this->assertTrue((bool) $event->transport_enabled);

        $form = $this->getJson("/api/v1/public/events/{$event->uuid}/registration-form")->assertOk();
        $this->assertTrue($form->json('data.form.services.accommodation_enabled'));
        $this->assertTrue($form->json('data.form.services.transport_enabled'));
        $this->assertSame('Executive Apartment', $form->json('data.form.services.accommodation_options.0.name'));
        $this->assertSame('NGN', $form->json('data.form.services.accommodation_options.0.currency'));
        $this->assertSame($route['id'], $form->json('data.form.services.transport_options.0.id'));
        $this->assertSame('Airport', $form->json('data.form.services.transport_options.0.origin'));
        $this->assertNotEmpty($form->json('data.form.services.accommodation_options.0.inventory'));

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Catalog Guest', 'email' => 'catalog.phase4@example.com', 'phone' => '+2348011000001'],
            'consent_accepted' => true,
            'accommodation' => [
                'option_id' => $option['id'],
                'occupancy_type' => 'private',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
            ],
            'transport_trips' => [[
                'option_id' => $route['id'],
                'trip_date' => now()->toDateString(),
                'trip_time' => '10:30',
                'pickup_location' => 'Arrival hall',
                'dropoff_location' => 'Hotel lobby',
                'passengers' => 1,
            ]],
        ])->assertSuccessful();
    }

    public function test_admin_can_edit_price_currency_and_historical_payments_stay(): void
    {
        $event = $this->unpublishedFlagsOff();
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Executive Apartment',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 4,
            'price' => 200000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $first = $this->registerGuest($event, 'Old Price', 'old.price.phase4@example.com', '+2348011000002');
        $this->postJson("/api/v1/events/registrations/{$first->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDay()->toDateString(),
        ])->assertOk();

        $oldPayment = EventRegistrationPayment::query()
            ->where('registration_id', $first->id)
            ->where('purpose', 'accommodation')
            ->firstOrFail();
        $this->assertEquals(200000, (float) $oldPayment->amount);
        $this->assertSame('NGN', $oldPayment->currency);
        $oldPayment->status = PaymentStatus::Paid;
        $oldPayment->paid_at = now();
        $oldPayment->save();

        $updated = $this->putJson("/api/v1/events/accommodation-options/{$option['id']}", [
            'price' => 250000,
            'currency' => 'USD',
            'name' => 'Executive Apartment Deluxe',
        ])->assertOk()->json('data.option');
        $this->assertEquals(250000, (float) $updated['price']);
        $this->assertSame('USD', $updated['currency']);
        $this->assertSame('Executive Apartment Deluxe', $updated['name']);

        $second = $this->registerGuest($event, 'New Price', 'new.price.phase4@example.com', '+2348011000003');
        $this->postJson("/api/v1/events/registrations/{$second->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDay()->toDateString(),
        ])->assertOk();

        $newPayment = EventRegistrationPayment::query()
            ->where('registration_id', $second->id)
            ->where('purpose', 'accommodation')
            ->firstOrFail();
        $this->assertEquals(250000, (float) $newPayment->amount);
        $this->assertSame('USD', $newPayment->currency);
        $oldPayment->refresh();
        $this->assertEquals(200000, (float) $oldPayment->amount);
        $this->assertSame('NGN', $oldPayment->currency);
        $this->assertSame(PaymentStatus::Paid->value, $oldPayment->status instanceof \BackedEnum ? $oldPayment->status->value : $oldPayment->status);
    }

    public function test_inactive_and_other_event_options_are_hidden(): void
    {
        $event = $this->unpublishedFlagsOff();
        $other = $this->unpublishedFlagsOff();
        $visible = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Visible Room',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 1,
            'price' => 100,
            'currency' => 'USD',
        ])->assertCreated()->json('data.option');
        $hidden = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Inactive Room',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 1,
            'price' => 90,
            'currency' => 'USD',
            'is_active' => false,
        ])->assertCreated()->json('data.option');
        $this->postJson("/api/v1/events/{$other->uuid}/accommodation-options", [
            'name' => 'Other Event Room',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 1,
            'price' => 80,
            'currency' => 'USD',
        ])->assertCreated();

        $this->putJson("/api/v1/events/accommodation-options/{$hidden['id']}", ['is_active' => false])->assertOk();

        $form = $this->getJson("/api/v1/public/events/{$event->uuid}/registration-form")->assertOk();
        $names = collect($form->json('data.form.services.accommodation_options'))->pluck('name')->all();
        $this->assertContains('Visible Room', $names);
        $this->assertNotContains('Inactive Room', $names);
        $this->assertNotContains('Other Event Room', $names);
        $this->assertSame($visible['id'], $form->json('data.form.services.accommodation_options.0.id'));
    }

    public function test_shared_group_uses_longest_stay_for_billing_and_keeps_actual_dates(): void
    {
        $event = $this->unpublishedFlagsOff();
        $a = $this->registerGuest($event, 'Ada One', 'ada.phase4@example.com', '+2348011000011');
        $b = $this->registerGuest($event, 'Ben Two', 'ben.phase4@example.com', '+2348011000012');
        $c = $this->registerGuest($event, 'Chi Three', 'chi.phase4@example.com', '+2348011000013');
        $d = $this->registerGuest($event, 'Dee Four', 'dee.phase4@example.com', '+2348011000014');
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Executive Apartment',
            'occupancy_type' => 'shared',
            'capacity' => 4,
            'unit_count' => 1,
            'price' => 200000,
            'currency' => 'NGN',
            'require_full_occupancy' => true,
        ])->assertCreated()->json('data.option');

        $checkIn = now()->toDateString();
        $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'shared',
            'arrival_date' => $checkIn,
            'departure_date' => now()->addDay()->toDateString(),
            'share_with' => [$b->uuid, $c->uuid, $d->uuid],
        ])->assertOk();

        $group = collect($this->getJson("/api/v1/events/{$event->uuid}/accommodation-dashboard")->json('data.pairings'))->first();
        $this->assertSame('1/4', $group['progress']);

        foreach ([$b, $c, $d] as $guest) {
            $this->postJson('/api/v1/events/accommodation-pairings/'.$group['uuid'].'/respond', [
                'registration_id' => $guest->uuid,
                'accept' => true,
                'arrival_date' => $checkIn,
                'departure_date' => now()->addDays(2)->toDateString(),
            ])->assertOk();
        }

        $pairing = EventAccommodationPairing::query()->where('uuid', $group['uuid'])->with('members')->firstOrFail();
        $this->assertSame('confirmed', $pairing->status);
        $this->assertSame(2, (int) $pairing->billable_nights);

        $aMember = $pairing->members->firstWhere('registration_id', $a->id);
        $this->assertSame(1, (int) $aMember->actual_nights);
        $this->assertSame($checkIn, $aMember->check_in_date?->toDateString());

        $amounts = EventRegistrationPayment::query()
            ->whereIn('registration_id', [$a->id, $b->id, $c->id, $d->id])
            ->where('purpose', 'accommodation')
            ->pluck('amount');
        $this->assertCount(4, $amounts);
        foreach ($amounts as $amount) {
            $this->assertEquals(100000, (float) $amount);
        }

        $this->postJson("/api/v1/events/registrations/{$b->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'shared',
            'arrival_date' => $checkIn,
            'departure_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $pairing->refresh();
        $this->assertSame(3, (int) $pairing->billable_nights);
        $bPayment = EventRegistrationPayment::query()
            ->where('registration_id', $b->id)
            ->where('purpose', 'accommodation')
            ->latest('id')
            ->firstOrFail();
        $this->assertEquals(150000, (float) $bPayment->amount);
    }

    public function test_transport_edit_currency_and_multiple_trips_persist(): void
    {
        $event = $this->unpublishedFlagsOff();
        $guest = $this->registerGuest($event, 'Trip Guest', 'trip.phase4@example.com', '+2348011000021');
        $option = $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Airport to Hotel',
            'route' => 'Airport → Hotel',
            'price' => 30000,
            'price_basis' => 'per_trip',
            'currency' => 'NGN',
            'vehicle_name' => 'Toyota Hiace',
        ])->assertCreated()->json('data.option');

        $updated = $this->putJson("/api/v1/events/transport-options/{$option['id']}", [
            'price' => 35000,
            'currency' => 'USD',
            'vehicle_name' => 'Toyota Hiace 14-seater',
            'origin' => 'Airport',
            'destination' => 'Hotel',
        ])->assertOk()->json('data.option');
        $this->assertEquals(35000, (float) $updated['price']);
        $this->assertSame('USD', $updated['currency']);

        $first = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/transport-trips", [
            'option_id' => $option['id'],
            'trip_date' => now()->toDateString(),
            'trip_time' => '10:30',
            'pickup_location' => 'Arrival hall',
            'dropoff_location' => 'Hotel',
            'passengers' => 1,
            'flight_info' => ['flight_number' => 'P47401', 'airport' => 'LOS'],
        ])->assertCreated()->json('data.trip');
        $this->assertEquals(35000, $first['amount']);
        $this->assertSame('USD', $first['currency']);
        $this->assertSame('10:30', $first['trip_time']);
        $this->assertSame('Arrival hall', $first['pickup_location']);

        $second = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/transport-trips", [
            'option_id' => $option['id'],
            'trip_date' => now()->addDay()->toDateString(),
            'trip_time' => '15:00',
            'pickup_location' => 'Hotel',
            'dropoff_location' => 'Airport',
            'passengers' => 1,
        ])->assertCreated()->json('data.trip');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertEquals(35000, $second['amount']);

        $workspace = $this->getJson("/api/v1/events/registrations/{$guest->uuid}")->assertOk();
        $this->assertCount(2, $workspace->json('data.transport_trips'));
    }

    public function test_workspace_and_export_include_actual_and_billable_fields(): void
    {
        Storage::fake('public');
        $event = $this->unpublishedFlagsOff();
        $guest = $this->registerGuest($event, 'Export Guest', 'export.phase4@example.com', '+2348011000031');
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Private Suite',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 2,
            'price' => 200000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');
        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $detail = $this->getJson("/api/v1/events/registrations/{$guest->uuid}")->assertOk();
        $this->assertNotEmpty($detail->json('data.allocation'));
        $this->assertNotEmpty($detail->json('data.service_payments'));

        [$headers, $rows] = app(RegistrationExportGenerator::class)->rowsForType('accommodation', $event->id, []);
        $this->assertContains('actual_nights', $headers);
        $this->assertContains('billable_nights', $headers);
        $this->assertContains('currency', $headers);
        $this->assertNotEmpty($rows);
        $this->assertSame('NGN', $rows[0]['currency']);
    }

    public function test_travel_enable_workspace_and_accommodation_report_rows(): void
    {
        $event = $this->unpublishedFlagsOff();
        $guest = $this->registerGuest($event, 'Travel Guest', 'travel.phase4@example.com', '+2348011000041');
        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/travel", [
            'origin' => 'Lagos',
            'destination' => 'Accra',
            'trip_type' => 'return',
            'travel_class' => 'economy',
            'departure_date' => now()->toDateString(),
            'preferred_departure_time' => '08:00',
            'return_date' => now()->addDays(4)->toDateString(),
            'preferred_return_time' => '18:00',
        ])->assertCreated();

        $event->refresh();
        $this->assertTrue((bool) $event->travel_assistance_enabled);
        $form = $this->getJson("/api/v1/public/events/{$event->uuid}/registration-form")->assertOk();
        $this->assertTrue($form->json('data.form.services.travel_assistance_enabled'));

        $workspace = $this->getJson("/api/v1/events/registrations/{$guest->uuid}")->assertOk();
        $this->assertSame('Lagos', $workspace->json('data.travel_request.origin'));
        $this->assertSame('08:00', $workspace->json('data.travel_request.preferred_departure_time'));

        $report = $this->postJson('/api/v1/events/reports', [
            'event_id' => $event->uuid,
            'report_type' => 'travel_summary',
        ])->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, $report->json('data.report.metrics.row_count'));
    }
}
