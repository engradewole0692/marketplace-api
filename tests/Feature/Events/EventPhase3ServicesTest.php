<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Services\EventDayService;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPhase3ServicesTest extends IamTestCase
{
    private function conference(): Event
    {
        $event = Event::query()->create([
            'title' => 'Phase 3 Conference',
            'slug' => 'phase3-conference-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(5)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'accommodation_enabled' => true,
            'transport_enabled' => true,
            'travel_assistance_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 200,
            'currency' => 'NGN',
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

    /**
     * @return array<string, mixed>
     */
    private function sharedApartment(Event $event, int $capacity = 4, int $units = 2): array
    {
        return $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Executive Apartment',
            'location' => 'Victoria Island',
            'occupancy_type' => 'shared',
            'capacity' => $capacity,
            'unit_count' => $units,
            'price' => 200000,
            'price_per_night' => 200000,
            'currency' => 'NGN',
            'require_full_occupancy' => true,
        ])->assertCreated()->json('data.option');
    }

    public function test_private_accommodation_pricing_and_nights(): void
    {
        $event = $this->conference();
        $guest = $this->registerGuest($event, 'Private Guest', 'private.phase3@example.com', '+2348010000001');
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Private Suite',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 5,
            'private_price' => 200000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $payment = EventRegistrationPayment::query()
            ->where('registration_id', $guest->id)
            ->where('purpose', 'accommodation')
            ->firstOrFail();
        $this->assertEquals(600000, (float) $payment->amount);
    }

    public function test_shared_four_person_group_pricing_invitations_and_common_dates(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->conference();
        $a = $this->registerGuest($event, 'Ada One', 'ada.phase3@example.com', '+2348010000011');
        $b = $this->registerGuest($event, 'Ben Two', 'ben.phase3@example.com', '+2348010000012');
        $c = $this->registerGuest($event, 'Chi Three', 'chi.phase3@example.com', '+2348010000013');
        $d = $this->registerGuest($event, 'Dee Four', 'dee.phase3@example.com', '+2348010000014');
        $option = $this->sharedApartment($event, 4, 2);

        $checkIn = now()->toDateString();
        $checkOut = now()->addDays(3)->toDateString();

        $pairing = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'shared',
            'arrival_date' => $checkIn,
            'departure_date' => $checkOut,
            'share_with' => [$b->uuid, $c->uuid, $d->uuid],
        ])->assertOk()->json('data');

        $search = $this->getJson("/api/v1/events/registrations/{$a->uuid}/accommodation/search?q=Ben")
            ->assertOk()
            ->json('data.participants');
        $this->assertNotEmpty($search);
        $this->assertArrayHasKey('name', $search[0]);
        $this->assertArrayNotHasKey('email', $search[0]);

        $pairingId = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid, $c->uuid, $d->uuid],
            'option_id' => $option['id'],
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
        ]);
        // A already has an active group from request(); second pairing must be rejected.
        $pairingId->assertUnprocessable();

        $dashboard = $this->getJson("/api/v1/events/{$event->uuid}/accommodation-dashboard")->assertOk();
        $groups = collect($dashboard->json('data.pairings'));
        $this->assertSame(1, $groups->count());
        $group = $groups->first();
        $this->assertSame('1/4', $group['progress']);
        $this->assertSame('pending_confirmation', $group['status']);

        $this->assertDatabaseHas('communication_email_logs', [
            'event_key' => 'event.accommodation.pairing.invited',
            'recipient_email' => 'ben.phase3@example.com',
        ]);

        foreach ([$b, $c, $d] as $guest) {
            $this->postJson('/api/v1/events/accommodation-pairings/'.$group['uuid'].'/respond', [
                'registration_id' => $guest->uuid,
                'accept' => true,
            ])->assertOk();
        }

        $confirmed = $this->getJson("/api/v1/events/{$event->uuid}/accommodation-dashboard")->json('data.pairings.0');
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame('4/4', $confirmed['progress']);

        $amounts = EventRegistrationPayment::query()
            ->whereIn('registration_id', [$a->id, $b->id, $c->id, $d->id])
            ->where('purpose', 'accommodation')
            ->pluck('amount');
        $this->assertCount(4, $amounts);
        foreach ($amounts as $amount) {
            $this->assertEquals(150000, (float) $amount);
        }
        $this->assertEquals(600000, (float) $amounts->sum());

        $this->postJson("/api/v1/events/registrations/{$a->uuid}/payments/approve", [
            'purpose' => 'accommodation',
        ])->assertOk();

        $this->assertTrue(
            EventRegistrationAuditLog::query()
                ->where('registration_id', $a->id)
                ->whereIn('event_type', ['pairing_requested', 'accommodation_allocated', 'pairing_confirmed'])
                ->exists()
        );
    }

    public function test_shared_two_and_three_person_capacity(): void
    {
        $event = $this->conference();
        $a = $this->registerGuest($event, 'Two A', 'two.a.phase3@example.com', '+2348010000021');
        $b = $this->registerGuest($event, 'Two B', 'two.b.phase3@example.com', '+2348010000022');
        $option = $this->sharedApartment($event, 2, 1);

        $pairing = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid],
            'option_id' => $option['id'],
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->json('data.pairing');

        $this->postJson('/api/v1/events/accommodation-pairings/'.$pairing['uuid'].'/respond', [
            'registration_id' => $b->uuid,
            'accept' => true,
        ])->assertOk()->assertJsonPath('data.pairing.status', 'confirmed');

        $c = $this->registerGuest($event, 'Three C', 'three.c.phase3@example.com', '+2348010000023');
        $d = $this->registerGuest($event, 'Three D', 'three.d.phase3@example.com', '+2348010000024');
        $e = $this->registerGuest($event, 'Three E', 'three.e.phase3@example.com', '+2348010000025');
        $triple = $this->sharedApartment($event, 3, 1);
        $group = $this->postJson("/api/v1/events/registrations/{$c->uuid}/accommodation/pairing", [
            'registration_ids' => [$d->uuid, $e->uuid],
            'option_id' => $triple['id'],
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->json('data.pairing');
        $this->assertSame('3', (string) $group['capacity']);
    }

    public function test_invalid_sharing_rules(): void
    {
        $event = $this->conference();
        $other = $this->conference();
        $a = $this->registerGuest($event, 'Self A', 'self.a.phase3@example.com', '+2348010000031');
        $b = $this->registerGuest($event, 'Self B', 'self.b.phase3@example.com', '+2348010000032');
        $outsider = $this->registerGuest($other, 'Other Event', 'other.event.phase3@example.com', '+2348010000033');
        $option = $this->sharedApartment($event, 2, 1);

        $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => ['not-a-real-registration'],
            'option_id' => $option['id'],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$outsider->uuid],
            'option_id' => $option['id'],
        ])->assertUnprocessable();

        $pairing = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid],
            'option_id' => $option['id'],
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.pairing');

        $this->postJson('/api/v1/events/accommodation-pairings/'.$pairing['uuid'].'/invite', [
            'registration_id' => $a->uuid,
            'registration_ids' => [$b->uuid],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/events/accommodation-pairings/'.$pairing['uuid'].'/invite', [
            'registration_id' => $a->uuid,
            'registration_ids' => [$a->uuid],
        ])->assertUnprocessable();

        $overflow = $this->registerGuest($event, 'Overflow', 'overflow.phase3@example.com', '+2348010000034');
        $this->postJson('/api/v1/events/accommodation-pairings/'.$pairing['uuid'].'/invite', [
            'registration_id' => $a->uuid,
            'registration_ids' => [$overflow->uuid],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/events/registrations/{$overflow->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid],
            'option_id' => $option['id'],
        ])->assertUnprocessable();
    }

    public function test_decline_and_mismatched_shared_dates(): void
    {
        $event = $this->conference();
        $a = $this->registerGuest($event, 'Date A', 'date.a.phase3@example.com', '+2348010000041');
        $b = $this->registerGuest($event, 'Date B', 'date.b.phase3@example.com', '+2348010000042');
        $c = $this->registerGuest($event, 'Date C', 'date.c.phase3@example.com', '+2348010000043');
        $d = $this->registerGuest($event, 'Date D', 'date.d.phase3@example.com', '+2348010000044');
        $option = $this->sharedApartment($event, 2, 2);

        $declined = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid],
            'option_id' => $option['id'],
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.pairing');

        $this->postJson('/api/v1/events/accommodation-pairings/'.$declined['uuid'].'/respond', [
            'registration_id' => $b->uuid,
            'accept' => false,
        ])->assertOk()->assertJsonPath('data.pairing.status', 'declined');

        $c->arrival_date = now()->toDateString();
        $c->departure_date = now()->addDays(3)->toDateString();
        $c->save();
        $d->arrival_date = now()->toDateString();
        $d->departure_date = now()->addDays(2)->toDateString();
        $d->save();

        $dates = $this->postJson("/api/v1/events/registrations/{$c->uuid}/accommodation/pairing", [
            'registration_ids' => [$d->uuid],
            'option_id' => $option['id'],
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.pairing');

        $this->postJson('/api/v1/events/accommodation-pairings/'.$dates['uuid'].'/respond', [
            'registration_id' => $d->uuid,
            'accept' => true,
        ])->assertUnprocessable();
    }

    public function test_incomplete_group_waits_for_partners(): void
    {
        $event = $this->conference();
        $a = $this->registerGuest($event, 'Wait A', 'wait.a.phase3@example.com', '+2348010000051');
        $option = $this->sharedApartment($event, 4, 1);

        $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'shared',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $dashboard = $this->getJson("/api/v1/events/{$event->uuid}/accommodation-dashboard")->assertOk();
        $this->assertSame('incomplete', $dashboard->json('data.pairings.0.status'));
        $this->assertSame(0, EventRegistrationPayment::query()->where('registration_id', $a->id)->where('purpose', 'accommodation')->count());
    }

    public function test_transport_trips_pricing_assignment_and_travel_workflow(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->conference();
        $guest = $this->registerGuest($event, 'Travel Guest', 'travel.phase3@example.com', '+2348010000061');

        $route = $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Airport to Hotel',
            'route' => 'Airport → Hotel',
            'price' => 30000,
            'price_basis' => 'per_trip',
            'currency' => 'NGN',
            'vehicle_name' => 'Coach 1',
        ])->assertCreated()->json('data.option');

        $perPassenger = $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Hotel to Venue',
            'route' => 'Hotel → Venue',
            'price' => 10000,
            'price_basis' => 'per_passenger',
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $trip = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/transport-trips", [
            'option_id' => $route['id'],
            'trip_date' => now()->toDateString(),
            'passengers' => 2,
        ])->assertCreated()->json('data.trip');
        $this->assertEquals(30000, $trip['amount']);

        $second = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/transport-trips", [
            'option_id' => $perPassenger['id'],
            'trip_date' => now()->addDay()->toDateString(),
            'passengers' => 3,
        ])->assertCreated()->json('data.trip');
        $this->assertEquals(30000, $second['amount']);

        $this->putJson('/api/v1/events/transport-trips/'.$trip['id'], [
            'status' => 'confirmed',
            'assigned_vehicle' => 'Coach 1',
        ])->assertOk();

        $this->putJson('/api/v1/events/transport-trips/'.$trip['id'], [
            'assigned_vehicle' => 'Coach 1',
        ])->assertOk();

        $this->assertDatabaseHas('communication_email_logs', [
            'event_key' => 'event.transport.confirmed',
            'recipient_email' => 'travel.phase3@example.com',
        ]);

        $travel = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/travel", [
            'trip_type' => 'return',
            'origin' => 'Lagos',
            'destination' => 'Accra',
            'departure_date' => now()->toDateString(),
            'return_date' => now()->addDays(4)->toDateString(),
            'travel_class' => 'economy',
            'passengers' => 1,
        ])->assertCreated()->json('data.travel');

        foreach (['under_review', 'quote_provided', 'awaiting_payment', 'booking_in_progress', 'booked'] as $status) {
            $payload = ['status' => $status];
            if ($status === 'quote_provided') {
                $payload['quote_amount'] = 180000;
            }
            $updated = $this->putJson('/api/v1/events/travel-requests/'.$travel['id'], $payload)->assertOk();
            $this->assertSame($status === 'quote_provided' ? 'quote_provided' : $status, $updated->json('data.travel.status'));
            $travel = $updated->json('data.travel');
        }

        $quotePayment = EventRegistrationPayment::query()
            ->where('registration_id', $guest->id)
            ->where('purpose', 'travel')
            ->firstOrFail();
        $this->assertEquals(180000, (float) $quotePayment->amount);

        $ops = $this->getJson("/api/v1/events/{$event->uuid}/ops-dashboard")->assertOk();
        $this->assertGreaterThanOrEqual(1, $ops->json('data.transport.trips'));
        $this->assertGreaterThanOrEqual(1, $ops->json('data.travel.requests'));
    }

    public function test_permissions_and_cross_event_isolation(): void
    {
        $event = $this->conference();
        $other = $this->conference();
        $guest = $this->registerGuest($event, 'Iso Guest', 'iso.phase3@example.com', '+2348010000071');
        $option = $this->sharedApartment($other, 2, 1);

        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/accommodation/allocate", [
            'option_id' => $option['id'],
        ])->assertNotFound();

        $stranger = User::factory()->create();
        $learner = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
        $stranger->permissions()->syncWithoutDetaching([$learner->id]);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Unauthorized',
            'capacity' => 2,
            'unit_count' => 1,
        ])->assertForbidden();

        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/self/accommodation", [
            'option_id' => $option['id'],
        ])->assertForbidden();
    }

    public function test_public_registration_form_catalog_and_payload(): void
    {
        $event = $this->conference();
        $this->sharedApartment($event, 4, 1);
        $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
            'name' => 'Airport to Hotel',
            'route' => 'Airport → Hotel',
            'price' => 30000,
            'price_basis' => 'per_trip',
        ])->assertCreated();

        $form = $this->getJson("/api/v1/public/events/{$event->uuid}/registration-form")->assertOk();
        $this->assertTrue($form->json('data.form.services.accommodation_enabled'));
        $this->assertNotEmpty($form->json('data.form.services.accommodation_options'));
        $this->assertNotEmpty($form->json('data.form.services.transport_options'));

        $optionId = $form->json('data.form.services.accommodation_options.0.id');
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Form Guest', 'email' => 'form.phase3@example.com', 'phone' => '+2348010000081'],
            'consent_accepted' => true,
            'accommodation' => [
                'option_id' => $optionId,
                'occupancy_type' => 'private',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
            ],
        ])->assertSuccessful();
    }

    public function test_registration_payment_is_isolated_from_accommodation_obligation(): void
    {
        $event = $this->conference();
        $guest = $this->registerGuest($event, 'Pay Isolate', 'pay.isolate.phase3@example.com', '+2348010000091');
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Private Suite',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 2,
            'price' => 200000,
            'price_per_night' => 200000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/accommodation/request", [
            'option_id' => $option['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->toDateString(),
            'departure_date' => now()->addDays(3)->toDateString(),
        ])->assertSuccessful();

        $accommodation = EventRegistrationPayment::query()
            ->where('registration_id', $guest->id)
            ->where('purpose', 'accommodation')
            ->firstOrFail();

        $registrationPayment = app(\App\Modules\Events\Services\EventPaymentService::class)
            ->ensurePendingPayment($guest->fresh('event'));

        $this->assertNotSame($accommodation->id, $registrationPayment->id);
        $this->assertContains($registrationPayment->purpose, [null, '', 'registration', 'event_registration']);
        $this->assertEquals(600000, (float) $accommodation->amount);
    }
}
