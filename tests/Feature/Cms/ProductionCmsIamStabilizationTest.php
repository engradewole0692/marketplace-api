<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Support\CmsCacheManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductionCmsIamStabilizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const STABILIZATION_MIGRATION = '2026_08_20_100000_production_cms_iam_stabilization';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
        ]);

        $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
    }

    public function test_admin_setting_update_flushes_public_bootstrap_cache(): void
    {
        Cache::put('cms:public:site-bootstrap', ['settings' => ['stale' => true], 'menus' => []], 300);
        Cache::put('cms:public:home', ['stale' => true], 300);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/cms/settings', [
                'settings' => [[
                    'key' => 'podcast_apple_url',
                    'value' => 'https://podcasts.apple.com/example',
                    'group' => 'media',
                    'is_public' => true,
                ]],
            ])
            ->assertSuccessful();

        $this->assertFalse(Cache::has('cms:public:site-bootstrap'));
        $this->assertFalse(Cache::has('cms:public:home'));
    }

    public function test_stabilization_migration_ensures_media_settings_permissions_and_flushes_cache(): void
    {
        DB::table('cms_settings')->whereIn('key', [
            'podcast_apple_url',
            'youversion_url',
            'youtube_channel_url',
        ])->delete();

        Cache::put('cms:public:site-bootstrap', ['stale' => true], 300);
        Cache::put('cms:public:home', ['stale' => true], 300);

        $this->rerunStabilizationMigration();

        foreach (['podcast_apple_url', 'youversion_url', 'youtube_channel_url'] as $key) {
            $setting = CmsSetting::query()->where('key', $key)->first();
            $this->assertNotNull($setting, "Missing CMS setting {$key}");
            $this->assertNotEmpty($setting->value);
            $this->assertTrue((bool) $setting->is_public);
        }

        $this->assertDatabaseHas('permissions', ['slug' => 'business-review.view']);
        $this->assertDatabaseHas('permissions', ['slug' => 'business-review.manage']);
        $this->assertDatabaseHas('permissions', ['slug' => 'communications.manage']);

        $this->assertTrue(
            $this->admin->hasPermission('business-review.manage')
            || $this->admin->roles()->where('slug', 'super_administrator')->exists(),
        );

        $this->assertFalse(Cache::has('cms:public:site-bootstrap'));
        $this->assertFalse(Cache::has('cms:public:home'));
    }

    public function test_stabilization_migration_does_not_overwrite_existing_setting_values(): void
    {
        CmsSetting::query()->updateOrCreate(
            ['key' => 'podcast_apple_url'],
            [
                'uuid' => (string) Str::uuid(),
                'group' => 'media',
                'value' => 'https://example.com/admin-entered-podcast',
                'type' => 'string',
                'is_public' => true,
            ],
        );

        $this->rerunStabilizationMigration();

        $setting = CmsSetting::query()->where('key', 'podcast_apple_url')->first();
        $this->assertSame('https://example.com/admin-entered-podcast', $setting?->value);
    }

    public function test_cache_manager_flushes_known_public_keys(): void
    {
        Cache::put('cms:public:site-bootstrap', ['a' => 1], 300);
        Cache::put('cms:public:home', ['b' => 2], 300);
        Cache::put('cms:public:page:about', ['c' => 3], 300);

        app(CmsCacheManager::class)->flushPage('about');

        $this->assertFalse(Cache::has('cms:public:site-bootstrap'));
        $this->assertFalse(Cache::has('cms:public:home'));
        $this->assertFalse(Cache::has('cms:public:page:about'));
    }

    private function rerunStabilizationMigration(): void
    {
        DB::table('migrations')
            ->where('migration', self::STABILIZATION_MIGRATION)
            ->delete();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::STABILIZATION_MIGRATION.'.php',
            '--force' => true,
        ])->assertSuccessful();
    }
}
