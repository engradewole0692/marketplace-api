<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Person;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Cms\Notifications\LogSmsNotifier;
use App\Modules\Cms\Notifications\LogWhatsAppNotifier;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventDayService;
use App\Modules\Events\Services\RegistrationExportGenerator;
use App\Support\GeoCatalog;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPhoneGeoOccupantsTest extends IamTestCase
{
    private function createEvent(array $overrides = []): Event
    {
        $event = Event::query()->create(array_merge([
            'title' => 'Geo Event',
            'slug' => 'geo-event-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(2)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'currency' => 'NGN',
            'accommodation_enabled' => true,
        ], $overrides));
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    public function test_public_geo_catalog_endpoints(): void
    {
        $this->getJson('/api/v1/public/geo/countries')
            ->assertOk()
            ->assertJsonPath('data.0.iso2', fn ($value) => is_string($value) && strlen($value) === 2);

        $this->getJson('/api/v1/public/geo/phone-countries')
            ->assertOk()
            ->assertJsonPath('data.0.dial', fn ($value) => is_string($value) && $value !== '');

        $this->getJson('/api/v1/public/geo/subdivisions?country=GH')
            ->assertOk()
            ->assertJsonPath('data.country.iso2', 'GH')
            ->assertJsonPath('data.subdivisions', fn ($rows) => is_array($rows) && count($rows) > 0);
    }

    public function test_international_phone_is_normalized_on_registration(): void
    {
        $event = $this->createEvent();
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Ama Mensah',
                'email' => 'ama.mensah@example.com',
                'phone' => '0241234567',
                'phone_country_code' => 'GH',
            ],
            'country' => 'GH',
            'state_region' => 'Greater Accra',
            'consent_accepted' => true,
        ])->assertSuccessful();

        $person = Person::query()->where('email', 'ama.mensah@example.com')->firstOrFail();
        $this->assertSame('+233241234567', $person->phone);
        $this->assertSame('GH', $person->phone_country_code);
        $this->assertSame('Greater Accra', $person->region);
    }

    public function test_shared_occupant_capacity_is_enforced(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent();
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Shared 2',
            'occupancy_type' => 'shared',
            'capacity' => 2,
            'unit_count' => 1,
            'price' => 40000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Primary Host',
                'email' => 'primary.host@example.com',
                'phone' => '+233241000001',
                'phone_country_code' => 'GH',
            ],
            'consent_accepted' => true,
            'accommodation' => [
                'option_id' => $option['id'],
                'occupancy_type' => 'shared',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
                'occupant_count' => 3,
                'occupants' => [
                    ['name' => 'Extra One', 'gender' => 'male', 'country' => 'GH', 'state_region' => 'Greater Accra'],
                    ['name' => 'Extra Two', 'gender' => 'female', 'country' => 'NG', 'state_region' => 'Lagos'],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_shared_occupants_are_exported_with_country_and_state(): void
    {
        Sanctum::actingAs($this->admin);
        $event = $this->createEvent();
        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Shared 3',
            'occupancy_type' => 'shared',
            'capacity' => 3,
            'unit_count' => 1,
            'price' => 40000,
            'currency' => 'NGN',
        ])->assertCreated()->json('data.option');

        $before = Person::query()->count();
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Export Host',
                'email' => 'export.host@example.com',
                'phone' => '+233241000002',
                'phone_country_code' => 'GH',
            ],
            'consent_accepted' => true,
            'accommodation' => [
                'option_id' => $option['id'],
                'occupancy_type' => 'shared',
                'arrival_date' => now()->toDateString(),
                'departure_date' => now()->addDays(2)->toDateString(),
                'occupant_count' => 2,
                'occupants' => [
                    ['name' => 'John Doe', 'gender' => 'Male', 'country' => 'GH', 'state_region' => 'Greater Accra'],
                ],
            ],
        ])->assertSuccessful();

        $this->assertSame($before + 1, Person::query()->count());
        [$headers, $rows] = app(RegistrationExportGenerator::class)->rowsForType('accommodation', $event->id, []);
        $this->assertContains('occupant_countries', $headers);
        $this->assertContains('occupant_states', $headers);
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => str_contains((string) ($row['occupant_countries'] ?? ''), 'GH')));
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => str_contains((string) ($row['occupant_states'] ?? ''), 'Greater Accra')));
    }

    public function test_log_sms_and_whatsapp_providers_do_not_claim_delivery(): void
    {
        $this->assertInstanceOf(LogSmsNotifier::class, app(SmsNotifierContract::class));
        $this->assertInstanceOf(LogWhatsAppNotifier::class, app(WhatsAppNotifierContract::class));
        $this->assertFalse(app(SmsNotifierContract::class)->send('+233241000099', 'hello'));
        $this->assertFalse(app(WhatsAppNotifierContract::class)->send('+233241000099', 'hello'));

        $row = app(OutboundMessageService::class)->send('whatsapp', '+233241000099', 'hello');
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error_message);
    }

    public function test_geo_catalog_is_the_same_source_used_for_occupants(): void
    {
        $this->assertTrue(GeoCatalog::isValidSubdivision('GH', 'Greater Accra'));
        $this->assertNotEmpty(GeoCatalog::phoneCountries());
    }
}
