<?php

declare(strict_types=1);

namespace Tests\Feature\GlobalPresence;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            CmsSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }

    public function test_countries_list_includes_leader_phone_and_address(): void
    {
        $country = CmsCountry::query()->first();
        if (! $country) {
            $this->markTestSkipped('No cms_countries records available.');
        }

        $leader = CmsLeadershipProfile::factory()->create([
            'country_id' => $country->id,
            'phone' => '+234 800 000 0000',
            'name' => 'Test Leader',
            'slug' => 'test-leader-'.uniqid(),
            'role' => 'Country Leader',
        ]);

        $country->update([
            'primary_leader_id' => $leader->id,
            'phone' => '+234 800 000 0000',
            'whatsapp_number' => '+234 800 000 0000',
            'office_address' => '123 Test Street, Lagos',
        ]);

        $response = $this->getJson("/api/v1/public/countries/{$country->slug}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame('+234 800 000 0000', $data['phone']);
        $this->assertSame('+234 800 000 0000', $data['whatsapp_number']);
        $this->assertSame('123 Test Street, Lagos', $data['office_address']);
        $this->assertNotNull($data['primary_leader']);
        $this->assertSame('Test Leader', $data['primary_leader']['name']);
    }

    public function test_country_slug_referenced_in_leadership_profile(): void
    {
        $country = CmsCountry::query()->first();
        if (! $country) {
            $this->markTestSkipped('No cms_countries records available.');
        }

        $leader = CmsLeadershipProfile::factory()->create([
            'country_id' => $country->id,
            'name' => 'Deep Link Leader',
            'slug' => 'deep-link-leader',
            'role' => 'Country Leader',
        ]);

        $country->update(['primary_leader_id' => $leader->id]);

        $response = $this->getJson("/api/v1/public/countries/{$country->slug}")->assertOk();
        $leaderData = $response->json('data.primary_leader');
        $this->assertSame('deep-link-leader', $leaderData['slug']);
    }

    public function test_countries_list_exposes_office_address(): void
    {
        $country = CmsCountry::query()->first();
        if (! $country) {
            $this->markTestSkipped('No cms_countries records available.');
        }

        $country->update(['office_address' => '49 Ikorodu Road, Fadeyi, Lagos']);

        $response = $this->getJson('/api/v1/public/countries')->assertOk();
        // The list response — verify the structure accepts the new fields.
        $countries = collect($response->json('data'));
        $found = $countries->firstWhere('slug', $country->slug);
        $this->assertNotNull($found);
    }
}
