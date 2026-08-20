<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Cms\Support\CmsCacheManager;
use App\Support\Iam\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production stabilization — applied automatically by Forge `php artisan migrate`.
 *
 * 1. Ensure homepage media CMS settings exist (fill missing/empty only).
 * 2. Ensure Global Presence leaders/phones/addresses exist (fill missing only).
 * 3. Sync IAM permissions from PermissionCatalog and attach to admin roles.
 * 4. Flush public CMS caches so the Vercel site reflects DB state immediately.
 *
 * Safe to re-run. Non-destructive. Never overwrites administrator-entered values.
 */
return new class extends Migration
{
    private const MEDIA_SETTINGS = [
        [
            'group' => 'media',
            'key' => 'podcast_title',
            'value' => 'The Tribe of Marketplace Ministers',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'podcast_description',
            'value' => 'Faith-driven conversations for executives, entrepreneurs, and marketplace leaders.',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'podcast_apple_url',
            'value' => 'https://podcasts.apple.com/de/podcast/the-tribe-of-marketplace-ministers/id1711093619',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youversion_url',
            'value' => 'https://www.bible.com/organizations/da5c986c-6fe1-473c-9994-afed7012043f',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youversion_title',
            'value' => 'We partner with YouVersion',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youversion_description',
            'value' => 'The Tribe of Marketplace Ministers is an official YouVersion partner — bringing the Word of God to the marketplace.',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youtube_channel_url',
            'value' => 'https://www.youtube.com/@thetribeofmarketplaceministers',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youtube_channel_title',
            'value' => 'Watch on YouTube',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'media',
            'key' => 'youtube_channel_description',
            'value' => 'New teachings, conference sessions, and marketplace ministry content — every week.',
            'type' => 'string',
            'is_public' => true,
        ],
        [
            'group' => 'vlog',
            'key' => 'vlog_youtube_channel_url',
            'value' => 'https://www.youtube.com/@thetribeofmarketplaceministers',
            'type' => 'string',
            'is_public' => true,
        ],
    ];

    private const LEADERS = [
        [
            'country_slug' => 'nigeria',
            'name' => 'Mercy Ochigbo-Obe',
            'slug' => 'mercy-ochigbo-obe',
            'role' => 'Country Leader',
            'phone' => '+234 809 062 2586',
            'category' => 'country',
        ],
        [
            'country_slug' => 'ghana',
            'name' => 'Naa Djama',
            'slug' => 'naa-djama',
            'role' => 'Country Leader',
            'phone' => '+233 24 685 3605',
            'category' => 'country',
        ],
        [
            'country_slug' => 'kenya',
            'name' => 'Stephen Nyayega',
            'slug' => 'stephen-nyayega',
            'role' => 'Country Leader',
            'phone' => '+254 718 124834',
            'category' => 'country',
        ],
        [
            'country_slug' => 'south-africa',
            'name' => 'Lily Mahlo',
            'slug' => 'lily-mahlo',
            'role' => 'Country Leader',
            'phone' => '+27 79 371 2576',
            'category' => 'country',
        ],
        [
            'country_slug' => 'rwanda',
            'name' => 'Emma Kayonde',
            'slug' => 'emma-kayonde',
            'role' => 'Country Leader',
            'phone' => '+250 791 944 681',
            'category' => 'country',
        ],
    ];

    private const OFFICES = [
        'nigeria' => '49 Ikorodu Road, Fadeyi Bus Stop, Jibowu, Yaba, Lagos',
        'ghana' => 'Holdbrook Plaza, 18th Lane, Osu, Accra Ghana',
        'usa' => 'Ekballo Ministries: 660 Westinghouse Blvd., Suite 108, Charlotte North Carolina 28273',
    ];

    private const ADMIN_PERMISSION_SLUGS = [
        'business-review.view',
        'business-review.manage',
        'communications.manage',
    ];

    public function up(): void
    {
        $this->ensureMediaSettings();
        $this->ensureGlobalPresence();
        $this->ensurePermissions();
        $this->flushPublicCmsCache();
    }

    public function down(): void
    {
        // Non-destructive — intentionally leaves seeded data and permissions in place.
    }

    private function ensureMediaSettings(): void
    {
        if (! $this->tableExists('cms_settings')) {
            return;
        }

        $now = now();

        foreach (self::MEDIA_SETTINGS as $setting) {
            $existing = DB::table('cms_settings')->where('key', $setting['key'])->first();

            if ($existing === null) {
                DB::table('cms_settings')->insert([
                    'uuid' => (string) Str::uuid(),
                    'group' => $setting['group'],
                    'key' => $setting['key'],
                    'value' => json_encode($setting['value']),
                    'type' => $setting['type'],
                    'is_public' => $setting['is_public'] ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            if ($this->settingValueIsEmpty($existing->value) && $setting['value'] !== '') {
                DB::table('cms_settings')->where('id', $existing->id)->update([
                    'value' => json_encode($setting['value']),
                    'is_public' => $setting['is_public'] ? 1 : 0,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function ensureGlobalPresence(): void
    {
        if (! $this->tableExists('cms_countries') || ! $this->tableExists('cms_leadership_profiles')) {
            return;
        }

        $now = now();
        $hasPrimaryLeader = $this->columnExists('cms_countries', 'primary_leader_id');
        $hasPhone = $this->columnExists('cms_countries', 'phone');
        $hasWhatsapp = $this->columnExists('cms_countries', 'whatsapp_number');
        $hasOffice = $this->columnExists('cms_countries', 'office_address');

        foreach (self::LEADERS as $leaderData) {
            $country = DB::table('cms_countries')->where('slug', $leaderData['country_slug'])->first();
            if ($country === null) {
                continue;
            }

            $existing = DB::table('cms_leadership_profiles')->where('slug', $leaderData['slug'])->first();

            if ($existing === null) {
                $leaderId = DB::table('cms_leadership_profiles')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $leaderData['name'],
                    'slug' => $leaderData['slug'],
                    'role' => $leaderData['role'],
                    'category' => $leaderData['category'],
                    'phone' => $leaderData['phone'],
                    'country_id' => $country->id,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $leaderId = $existing->id;
                $leaderPatch = ['updated_at' => $now];
                if (empty($existing->phone)) {
                    $leaderPatch['phone'] = $leaderData['phone'];
                }
                if (empty($existing->country_id)) {
                    $leaderPatch['country_id'] = $country->id;
                }
                if (empty($existing->category)) {
                    $leaderPatch['category'] = $leaderData['category'];
                }
                if (count($leaderPatch) > 1) {
                    DB::table('cms_leadership_profiles')->where('id', $existing->id)->update($leaderPatch);
                }
            }

            $countryPatch = ['updated_at' => $now];
            if ($hasPrimaryLeader && empty($country->primary_leader_id)) {
                $countryPatch['primary_leader_id'] = $leaderId;
            }
            if ($hasPhone && empty($country->phone)) {
                $countryPatch['phone'] = $leaderData['phone'];
            }
            if ($hasWhatsapp && empty($country->whatsapp_number)) {
                $countryPatch['whatsapp_number'] = $leaderData['phone'];
            }
            if (count($countryPatch) > 1) {
                DB::table('cms_countries')->where('id', $country->id)->update($countryPatch);
            }
        }

        if (! $hasOffice) {
            return;
        }

        foreach (self::OFFICES as $slug => $address) {
            $country = DB::table('cms_countries')->where('slug', $slug)->first();
            if ($country === null || ! empty($country->office_address)) {
                continue;
            }

            DB::table('cms_countries')->where('id', $country->id)->update([
                'office_address' => $address,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensurePermissions(): void
    {
        if (! $this->tableExists('permissions')) {
            return;
        }

        foreach (PermissionCatalog::all() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'module' => $permission['module'],
                    'group' => $permission['group'],
                    'description' => $permission['description'],
                    'is_system' => true,
                ],
            );
        }

        $superAdmin = Role::query()->where('slug', 'super_administrator')->first();
        if ($superAdmin !== null) {
            $superAdmin->permissions()->syncWithoutDetaching(
                Permission::query()->pluck('id')->all(),
            );
        }

        $administrator = Role::query()->where('slug', 'administrator')->first();
        if ($administrator !== null) {
            $ids = Permission::query()
                ->whereIn('slug', self::ADMIN_PERMISSION_SLUGS)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $administrator->permissions()->syncWithoutDetaching($ids);
            }
        }
    }

    private function flushPublicCmsCache(): void
    {
        try {
            app(CmsCacheManager::class)->flushPublic();
        } catch (\Throwable) {
            foreach ([
                'cms:public:site-bootstrap',
                'cms:public:home',
                'cms:public:sitemap',
            ] as $key) {
                Cache::forget($key);
            }
        }
    }

    private function settingValueIsEmpty(mixed $raw): bool
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if ($decoded === null || $decoded === '' || $decoded === []) {
            return true;
        }

        return is_string($raw) && in_array(trim($raw), ['', '""', 'null', '[]'], true);
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }
};
