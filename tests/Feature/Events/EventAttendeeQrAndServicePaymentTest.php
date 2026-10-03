<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\MemberNotificationMail;
use App\Models\Person;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationSeederDefaults;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Http\Requests\StoreRegistrationRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCheckInToken;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Services\EventDayService;
use App\Modules\Events\Support\EventRegistrationQr;
use App\Modules\Events\Support\EventServicePaymentInstructions;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Iam\IamTestCase;

final class EventAttendeeQrAndServicePaymentTest extends IamTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'Attendee QR Event',
            'slug' => 'attendee-qr-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(2)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'main_hall_capacity' => 20,
            'overflow_capacity' => 5,
            'seating_policy' => 'main_then_overflow',
            'currency' => 'NGN',
        ], $overrides));
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    /**
     * @return array{accommodation: array<string, mixed>, transport: array<string, mixed>}
     */
    private function createServiceOptions(Event $event): array
    {
        $accommodation = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Private Room',
            'occupancy_type' => 'private',
            'capacity' => 1,
            'unit_count' => 10,
            'price' => 50000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $transport = $this->postJson("/api/v1/events/{$event->uuid}/transport-options", [
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

        return ['accommodation' => $accommodation, 'transport' => $transport];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function publicPayload(Event $event, string $name, string $email, array $extra = []): array
    {
        return array_merge([
            'event_id' => $event->uuid,
            'registrant' => ['name' => $name, 'email' => $email, 'phone' => '+23480'.random_int(10000000, 99999999)],
            'consent_accepted' => true,
        ], $extra);
    }

    private function assertOpaqueToken(?string $token): void
    {
        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{24}$/', (string) $token);
    }

    public function test_every_public_registrant_gets_a_unique_attendee_qr_even_when_check_in_is_disabled(): void
    {
        $event = $this->createEvent(['check_in_enabled' => false]);

        $first = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Ada Obi', 'ada.obi@example.com'))
            ->assertCreated();
        $second = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Bola Ade', 'bola.ade@example.com'))
            ->assertCreated();

        $tokenA = $first->json('data.registration.check_in_token');
        $tokenB = $second->json('data.registration.check_in_token');
        $this->assertOpaqueToken($tokenA);
        $this->assertOpaqueToken($tokenB);
        $this->assertNotSame($tokenA, $tokenB);

        $first->assertJsonPath('data.registration.attendee_qr_kind', EventRegistrationQr::KIND_ATTENDEE_CHECK_IN);
        $qrUrl = (string) $first->json('data.registration.attendee_qr_image_url');
        $this->assertSame(EventRegistrationQr::attendeeImageUrl($tokenA), $qrUrl);
        $this->assertStringNotContainsString(rawurlencode(EventRegistrationQr::publicUrl($event)), $qrUrl);
        $this->assertStringNotContainsString('ada.obi', $qrUrl);
        $this->assertStringNotContainsString('Ada', $qrUrl);

        $registration = EventRegistration::query()->where('uuid', $first->json('data.registration.id'))->firstOrFail();
        $row = EventCheckInToken::query()->where('registration_id', $registration->id)->sole();
        $this->assertSame(hash('sha256', $tokenA), $row->token_hash);
        $this->assertStringNotContainsString($tokenA, json_encode($row->getAttributes()) ?: '');
    }

    public function test_resubmitting_public_registration_preserves_the_delivered_token(): void
    {
        $event = $this->createEvent();
        $payload = $this->publicPayload($event, 'Chidi Eze', 'chidi.eze@example.com');

        $token = $this->postJson('/api/v1/public/events/registrations', $payload)->assertCreated()->json('data.registration.check_in_token');
        $again = $this->postJson('/api/v1/public/events/registrations', $payload)->assertOk();

        $this->assertSame($token, $again->json('data.registration.check_in_token'));
        $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, EventCheckInToken::query()->where('event_id', $event->id)->count());
    }

    public function test_existing_person_registration_reuses_person_and_token_checks_in_once(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(10, 0));
        $event = $this->createEvent();
        $person = Person::factory()->create([
            'display_name' => 'Ngozi Okafor',
            'email' => 'ngozi.okafor@example.com',
            'phone' => '+2348031112222',
        ]);
        $personCount = Person::query()->count();

        $token = $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Ngozi Okafor', 'email' => 'ngozi.okafor@example.com', 'phone' => '+2348031112222'],
            'consent_accepted' => true,
        ])->assertCreated()->json('data.registration.check_in_token');

        $this->assertOpaqueToken($token);
        $this->assertSame($personCount, Person::query()->count());
        $registration = EventRegistration::query()->where('event_id', $event->id)->sole();
        $this->assertSame($person->id, $registration->person_id);

        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $token, 'event_id' => $event->uuid])
            ->assertOk()
            ->assertJsonPath('data.participant.identity.registration_number', $registration->registration_number);

        $scan = $this->postJson('/api/v1/events/check-in/scan', ['token' => $token, 'event_id' => $event->uuid])->assertCreated();
        $this->assertSame('main_hall', $scan->json('data.check_in.seating_area'));
        $this->assertTrue((bool) $scan->json('data.check_in.counts_toward_seating'));
        $this->assertDatabaseHas('event_day_attendances', ['registration_id' => $registration->id]);

        $duplicate = $this->postJson('/api/v1/events/check-in/scan', ['token' => $token, 'event_id' => $event->uuid])->assertStatus(422);
        $this->assertStringContainsString('Already checked in', (string) $duplicate->json('errors.registration.0'));

        $ops = $this->getJson("/api/v1/events/{$event->uuid}/ops-dashboard")->assertOk();
        $this->assertSame(1, $ops->json('data.seating.main_hall.occupied'));

        Carbon::setTestNow();
    }

    public function test_quick_on_site_registration_returns_attendee_qr_for_new_and_existing_person(): void
    {
        $event = $this->createEvent(['check_in_enabled' => false]);

        $created = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Walk In', 'email' => 'walk.in@example.com', 'phone' => '+2348040000001'],
            'consent_accepted' => true,
        ])->assertCreated();
        $walkInToken = $created->json('data.registration.check_in_token');
        $this->assertOpaqueToken($walkInToken);
        $this->assertNotEmpty($created->json('data.registration.attendee_qr_image_url'));

        $existingPerson = Person::factory()->create([
            'display_name' => 'Existing Person',
            'email' => 'existing.person@example.com',
            'phone' => '+2348040000002',
        ]);
        $personCount = Person::query()->count();

        $existing = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'person_id' => $existingPerson->uuid,
            'consent_accepted' => true,
        ])->assertCreated();
        $existingToken = $existing->json('data.registration.check_in_token');
        $this->assertOpaqueToken($existingToken);
        $this->assertNotSame($walkInToken, $existingToken);
        $this->assertSame($personCount, Person::query()->count());

        $reused = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'person_id' => $existingPerson->uuid,
            'consent_accepted' => true,
        ])->assertOk();
        $this->assertSame($existingToken, $reused->json('data.registration.check_in_token'));
        $this->assertSame(2, EventRegistration::query()->where('event_id', $event->id)->count());
        $this->assertSame(2, EventCheckInToken::query()->where('event_id', $event->id)->count());

        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $existingToken, 'event_id' => $event->uuid])
            ->assertOk()
            ->assertJsonPath('data.participant.identity.registration_number', $existing->json('data.registration.registration_number'));
    }

    public function test_yes_without_accommodation_option_or_transport_route_is_rejected(): void
    {
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $this->createServiceOptions($event);

        $noRoom = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'No Room', 'no.room@example.com', [
            'accommodation_required' => true,
        ]))->assertStatus(422);
        $this->assertSame(StoreRegistrationRequest::ACCOMMODATION_OPTION_REQUIRED, $noRoom->json('errors')['accommodation.option_id'][0] ?? null);

        $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'No Route', 'no.route@example.com', [
            'transport_required' => true,
            'transport_trips' => [['option_id' => '', 'passengers' => 1]],
        ]))->assertStatus(422)->assertJsonPath('errors.transport_trips.0', StoreRegistrationRequest::TRANSPORT_ROUTE_REQUIRED);

        $this->assertSame(0, EventRegistration::query()->where('event_id', $event->id)->count());
    }

    public function test_required_standard_stay_fields_do_not_block_public_registration_when_catalog_replaces_them(): void
    {
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $options = $this->createServiceOptions($event);
        $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
            'settings' => [
                ['field_key' => 'arrival_date', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
                ['field_key' => 'departure_date', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
                ['field_key' => 'accommodation_required', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
                ['field_key' => 'airport_pickup_required', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
            ],
        ])->assertOk();

        $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'No Services', 'no.services@example.com'))
            ->assertCreated()
            ->assertJsonPath('data.registration.payment_instructions', null);

        $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Ride Only', 'ride.only@example.com', [
            'transport_required' => true,
            'transport_trips' => [['option_id' => $options['transport']['id'], 'passengers' => 1]],
        ]))->assertCreated();

        $catalogOff = $this->createEvent();
        $this->putJson("/api/v1/events/{$catalogOff->uuid}/registration-field-settings", [
            'settings' => [
                ['field_key' => 'arrival_date', 'is_enabled' => true, 'is_required' => true, 'show_on_public' => true],
            ],
        ])->assertOk();
        $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($catalogOff, 'Needs Arrival', 'needs.arrival@example.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['arrival_date']);
    }

    public function test_confirmation_exposes_persisted_accommodation_and_transport_details(): void
    {
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $options = $this->createServiceOptions($event);
        $arrival = now()->toDateString();
        $departure = now()->addDays(2)->toDateString();

        $both = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Detail Both', 'detail.both@example.com', [
            'accommodation_required' => true,
            'accommodation' => [
                'option_id' => $options['accommodation']['id'],
                'occupancy_type' => 'private',
                'arrival_date' => $arrival,
                'departure_date' => $departure,
            ],
            'transport_required' => true,
            'transport_trips' => [['option_id' => $options['transport']['id'], 'passengers' => 2]],
        ]))->assertCreated();

        $selections = collect($both->json('data.registration.payment_instructions.selections'))->keyBy('type');
        $this->assertSame(['accommodation', 'transport'], $selections->keys()->all());
        $stay = implode("\n", $selections['accommodation']['lines']);
        $this->assertStringContainsString('Option: Private Room', $stay);
        $this->assertStringContainsString('Check-in: '.$arrival, $stay);
        $this->assertStringContainsString('Check-out: '.$departure, $stay);
        $this->assertStringContainsString('Nights: 2', $stay);
        $this->assertStringContainsString('Estimated amount: NGN 100,000', $stay);
        $ride = implode("\n", $selections['transport']['lines']);
        $this->assertStringContainsString('Airport pickup to accommodation', $ride);
        $this->assertStringContainsString('2 passengers', $ride);
        $this->assertStringNotContainsString((string) $both->json('data.registration.check_in_token'), (string) json_encode($selections));

        $rideOnly = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Detail Ride', 'detail.ride@example.com', [
            'transport_required' => true,
            'transport_trips' => [['option_id' => $options['transport']['id'], 'passengers' => 1]],
        ]))->assertCreated();
        $this->assertSame(['transport'], collect($rideOnly->json('data.registration.payment_instructions.selections'))->pluck('type')->all());
    }

    public function test_quick_registration_with_services_returns_payment_details_qr_and_sends_email(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $this->createServiceOptions($event);
        $this->putJson("/api/v1/events/{$event->uuid}/registration-field-settings", [
            'settings' => [
                ['field_key' => 'airport_pickup_required', 'is_enabled' => true, 'show_on_quick' => true],
            ],
        ])->assertOk();

        $response = $this->postJson('/api/v1/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Desk Ride', 'email' => 'desk.ride@example.com', 'phone' => '+2348040000077'],
            'consent_accepted' => true,
            'airport_pickup_required' => true,
        ])->assertCreated();

        $token = (string) $response->json('data.registration.check_in_token');
        $this->assertOpaqueToken($token);
        $response->assertJsonPath('data.registration.payment_instructions.scope', 'transport')
            ->assertJsonPath('data.registration.payment_instructions.account_number', '1022860128');
        $this->assertSame('transport', $response->json('data.registration.payment_instructions.selections.0.type'));

        Mail::assertSent(CommunicationMailable::class, function (CommunicationMailable $mail) use ($token): bool {
            return $mail->hasTo('desk.ride@example.com')
                && str_contains($mail->htmlBody, rawurlencode($token))
                && str_contains($mail->htmlBody, 'Your service selections:')
                && str_contains($mail->htmlBody, 'Transportation requested')
                && str_contains($mail->htmlBody, 'Account Number: 1022860128');
        });

        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $token, 'event_id' => $event->uuid])->assertOk();
    }

    public function test_organization_field_label_includes_school_for_new_and_untouched_existing_configs(): void
    {
        $event = $this->createEvent();
        $label = collect($this->getJson("/api/v1/events/{$event->uuid}/registration-field-settings")->assertOk()->json('data.settings'))
            ->firstWhere('field_key', 'organization')['label'] ?? null;
        $this->assertSame('Organization / Company / School', $label);

        $migration = require database_path('migrations/2026_10_03_090000_relabel_event_registration_organization_field.php');
        $setting = $event->registrationFieldSettings()->where('field_key', 'organization')->sole();
        $setting->forceFill(['label' => 'Organization / company'])->save();
        $migration->up();
        $this->assertSame('Organization / Company / School', $setting->fresh()->label);

        $setting->forceFill(['label' => 'Employer'])->save();
        $migration->up();
        $this->assertSame('Employer', $setting->fresh()->label);
    }

    public function test_legacy_yes_no_fields_are_not_blocked_when_service_catalog_is_off(): void
    {
        $event = $this->createEvent(['accommodation_enabled' => false, 'transport_enabled' => false]);

        $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Legacy Flag', 'legacy.flag@example.com', [
            'accommodation_required' => true,
            'airport_pickup_required' => true,
        ]))->assertCreated();
    }

    public function test_confirmation_payment_instructions_follow_actual_selection_and_never_mark_paid(): void
    {
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $options = $this->createServiceOptions($event);
        $stay = [
            'option_id' => $options['accommodation']['id'],
            'occupancy_type' => 'private',
            'arrival_date' => now()->subDay()->toDateString(),
            'departure_date' => now()->addDays(3)->toDateString(),
        ];
        $trip = [['option_id' => $options['transport']['id'], 'passengers' => 1]];

        $neither = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Neither One', 'neither.one@example.com'))
            ->assertCreated();
        $this->assertNull($neither->json('data.registration.payment_instructions'));

        $lodging = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Stay One', 'stay.one@example.com', [
            'accommodation_required' => true,
            'accommodation' => $stay,
        ]))->assertCreated();
        $lodging->assertJsonPath('data.registration.payment_instructions.scope', 'accommodation')
            ->assertJsonPath('data.registration.payment_instructions.notice', EventServicePaymentInstructions::NOTICE)
            ->assertJsonPath('data.registration.payment_instructions.account_name', 'Luvanex International Limited')
            ->assertJsonPath('data.registration.payment_instructions.bank', 'UBA (United Bank for Africa)')
            ->assertJsonPath('data.registration.payment_instructions.account_number', '1022860128')
            ->assertJsonPath('data.registration.payment_instructions.whatsapp_contacts.0.display', '+234 707 638 3446')
            ->assertJsonPath('data.registration.payment_instructions.whatsapp_contacts.1.display', '+234 903 096 7597');
        $this->assertOpaqueToken($lodging->json('data.registration.check_in_token'));

        $ride = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Ride One', 'ride.one@example.com', [
            'transport_required' => true,
            'transport_trips' => $trip,
        ]))->assertCreated();
        $ride->assertJsonPath('data.registration.payment_instructions.scope', 'transport');

        $both = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Both One', 'both.one@example.com', [
            'accommodation_required' => true,
            'accommodation' => $stay,
            'transport_required' => true,
            'transport_trips' => $trip,
        ]))->assertCreated();
        $both->assertJsonPath('data.registration.payment_instructions.scope', 'accommodation_and_transport');
        $this->assertStringContainsString('accommodation and transportation', (string) $both->json('data.registration.payment_instructions.manual_payment_notice'));

        $statuses = EventRegistrationPayment::query()
            ->where('event_id', $event->id)
            ->pluck('status')
            ->map(fn ($status) => $status instanceof \BackedEnum ? $status->value : (string) $status)
            ->unique()
            ->values()
            ->all();
        $this->assertNotContains('paid', $statuses);
        $this->assertNotContains('approved', $statuses);
        $this->assertNotContains('waived', $statuses);
    }

    public function test_manual_payment_wording_and_plain_text_block(): void
    {
        $accommodation = EventServicePaymentInstructions::payload(true, false);
        $this->assertStringContainsString('made manually by bank transfer', $accommodation['manual_payment_notice']);
        $this->assertStringContainsString('no online payment for accommodation', $accommodation['manual_payment_notice']);
        $this->assertStringContainsString('not confirmed automatically', $accommodation['manual_payment_notice']);

        $transport = EventServicePaymentInstructions::payload(false, true);
        $this->assertStringContainsString('no online payment for transportation', $transport['manual_payment_notice']);

        $text = EventServicePaymentInstructions::plainText(EventServicePaymentInstructions::payload(true, true));
        $this->assertSame(1, substr_count($text, EventServicePaymentInstructions::NOTICE));
        $this->assertStringContainsString('Account Name: Luvanex International Limited', $text);
        $this->assertStringContainsString('Bank: UBA (United Bank for Africa)', $text);
        $this->assertStringContainsString('Account Number: 1022860128', $text);
        $this->assertStringContainsString('Joy: +234 707 638 3446', $text);
        $this->assertStringContainsString('Kenny: +234 903 096 7597', $text);
        $this->assertStringContainsString('WhatsApp', $text);

        $this->assertSame('', EventServicePaymentInstructions::plainText(null));
    }

    public function test_confirmation_email_includes_qr_and_payment_block_only_when_applicable(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->createEvent(['check_in_enabled' => false, 'accommodation_enabled' => true, 'transport_enabled' => true]);
        $options = $this->createServiceOptions($event);

        $withService = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Mail Stay', 'mail.stay@example.com', [
            'accommodation_required' => true,
            'accommodation' => [
                'option_id' => $options['accommodation']['id'],
                'occupancy_type' => 'private',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
            ],
        ]))->assertCreated();
        $withToken = (string) $withService->json('data.registration.check_in_token');

        $plain = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Mail Plain', 'mail.plain@example.com'))
            ->assertCreated();
        $plainToken = (string) $plain->json('data.registration.check_in_token');

        Mail::assertSent(CommunicationMailable::class, function (CommunicationMailable $mail) use ($withToken): bool {
            if (! $mail->hasTo('mail.stay@example.com')) {
                return false;
            }

            return str_contains($mail->htmlBody, $withToken)
                && str_contains($mail->htmlBody, rawurlencode($withToken))
                && str_contains($mail->htmlBody, 'Your service selections:')
                && str_contains($mail->htmlBody, 'Option: Private Room')
                && str_contains($mail->htmlBody, 'Account Number: 1022860128')
                && str_contains($mail->htmlBody, 'Luvanex International Limited')
                && str_contains($mail->htmlBody, 'no online payment for accommodation')
                && ! str_contains(strtolower($mail->htmlBody), 'payment verified');
        });

        Mail::assertSent(CommunicationMailable::class, function (CommunicationMailable $mail) use ($plainToken): bool {
            if (! $mail->hasTo('mail.plain@example.com')) {
                return false;
            }

            return str_contains($mail->htmlBody, $plainToken)
                && ! str_contains($mail->htmlBody, '1022860128')
                && ! str_contains($mail->htmlBody, 'Luvanex');
        });
    }

    public function test_confirmation_email_without_template_row_still_carries_qr_and_payment(): void
    {
        Mail::fake();
        $this->assertSame(0, CommunicationTemplate::query()->where('event_key', 'event.registration.confirmed')->count());
        $event = $this->createEvent(['accommodation_enabled' => true, 'transport_enabled' => true]);
        $options = $this->createServiceOptions($event);

        $token = (string) $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Fallback Ride', 'fallback.ride@example.com', [
            'transport_required' => true,
            'transport_trips' => [['option_id' => $options['transport']['id'], 'passengers' => 1]],
        ]))->assertCreated()->json('data.registration.check_in_token');
        $plainToken = (string) $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Fallback Plain', 'fallback.plain@example.com'))
            ->assertCreated()
            ->json('data.registration.check_in_token');

        Mail::assertSent(MemberNotificationMail::class, function (MemberNotificationMail $mail) use ($token): bool {
            if (! $mail->hasTo('fallback.ride@example.com')) {
                return false;
            }
            $html = $mail->render();

            return str_contains($html, $token)
                && str_contains($html, rawurlencode($token))
                && str_contains($html, 'Account Number: 1022860128')
                && str_contains($html, 'no online payment for transportation')
                && ! str_contains($html, 'Notification: <strong>event.registration.confirmed');
        });

        Mail::assertSent(MemberNotificationMail::class, function (MemberNotificationMail $mail) use ($plainToken): bool {
            if (! $mail->hasTo('fallback.plain@example.com')) {
                return false;
            }
            $html = $mail->render();

            return str_contains($html, $plainToken) && ! str_contains($html, '1022860128');
        });
    }

    public function test_email_template_migration_upgrades_only_the_untouched_default(): void
    {
        $migration = require database_path('migrations/2026_10_02_090000_add_payment_instructions_to_event_registration_email.php');
        $defaults = app(CommunicationSeederDefaults::class)->templateDefaults('event.registration.confirmed');

        $template = CommunicationTemplate::query()->updateOrCreate(
            ['event_key' => 'event.registration.confirmed'],
            array_merge($defaults, ['html_body' => $migration::PREVIOUS_DEFAULT_BODY]),
        );

        $migration->up();
        $template->refresh();
        $this->assertSame($defaults['html_body'], $template->html_body);
        $this->assertStringContainsString('{{payment_instructions_text}}', (string) $template->html_body);
        $this->assertStringContainsString('{{qr_image_url}}', (string) $template->html_body);

        $customBody = '<p>Custom admin wording {{qr_image_url}}</p>';
        $template->forceFill(['html_body' => $customBody])->save();
        $migration->up();
        $this->assertSame($customBody, $template->fresh()->html_body);
    }

    public function test_reset_invalidates_attendee_token_and_undo_restores_it(): void
    {
        Carbon::setTestNow(now()->startOfDay()->setTime(10, 0));
        $event = $this->createEvent();
        $token = $this->postJson('/api/v1/public/events/registrations', $this->publicPayload($event, 'Reset Person', 'reset.person@example.com'))
            ->assertCreated()
            ->json('data.registration.check_in_token');
        $number = EventRegistration::query()->where('event_id', $event->id)->value('registration_number');

        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $token, 'event_id' => $event->uuid])->assertOk();

        $this->postJson("/api/v1/events/{$event->uuid}/registration-data/reset", [
            'confirm' => true,
            'confirmation' => 'RESET REGISTRATIONS',
        ])->assertOk();

        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $token, 'event_id' => $event->uuid])->assertStatus(422);
        $this->assertSame(0, EventCheckInToken::query()->where('event_id', $event->id)->count());

        $this->postJson("/api/v1/events/{$event->uuid}/registration-data/undo", [
            'confirm' => true,
            'confirmation' => 'UNDO RESET',
        ])->assertOk();

        $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, EventCheckInToken::query()->where('event_id', $event->id)->count());
        $this->postJson('/api/v1/events/check-in/lookup', ['token' => $token, 'event_id' => $event->uuid])
            ->assertOk()
            ->assertJsonPath('data.participant.identity.registration_number', $number);
        $this->postJson('/api/v1/events/check-in/scan', ['token' => $token, 'event_id' => $event->uuid])->assertCreated();

        Carbon::setTestNow();
    }
}
