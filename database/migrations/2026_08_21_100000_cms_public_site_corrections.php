<?php

declare(strict_types=1);

use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CMS public-site corrections:
 * - Seed Rwanda country (idempotent)
 * - Restructure primary nav: Home, About(+children), Ministries, Connect(+children), Contact
 * - Update home hero CTA defaults + story_video_url field
 * - Add counselling_cases.who_is_this_for
 * - Flush public CMS cache
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureRwandaCountry();
        $this->restructurePrimaryNavigation();
        $this->updateHomeHeroDefaults();
        $this->ensureCounsellingWhoIsThisForColumn();
        $this->flushPublicCmsCache();
    }

    public function down(): void
    {
        if (Schema::hasTable('counselling_cases') && Schema::hasColumn('counselling_cases', 'who_is_this_for')) {
            Schema::table('counselling_cases', function (Blueprint $table): void {
                $table->dropColumn('who_is_this_for');
            });
        }
    }

    private function ensureRwandaCountry(): void
    {
        if (! Schema::hasTable('cms_countries')) {
            return;
        }

        $existing = DB::table('cms_countries')->where('slug', 'rwanda')->first();
        if ($existing !== null) {
            return;
        }

        // Skip when the table is empty (fresh migrate before CmsSeeder).
        // Production already has countries; insert Rwanda then.
        if (DB::table('cms_countries')->count() === 0) {
            return;
        }

        $maxSort = (int) (DB::table('cms_countries')->max('sort_order') ?? 0);
        $now = now();

        DB::table('cms_countries')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Rwanda',
            'slug' => 'rwanda',
            'code' => 'RW',
            'region' => 'Africa',
            'flag_emoji' => '🇷🇼',
            'summary' => 'Marketplace ministers gathering and deploying across Rwanda.',
            'content' => json_encode([
                'status' => 'Active',
                'members' => '',
                'meeting' => '',
            ]),
            'is_active' => true,
            'sort_order' => $maxSort + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Attach seeded leader if present from prior migration.
        $leader = DB::table('cms_leadership_profiles')->where('slug', 'emma-kayonde')->first();
        $country = DB::table('cms_countries')->where('slug', 'rwanda')->first();
        if ($leader && $country && Schema::hasColumn('cms_countries', 'primary_leader_id')) {
            $patch = ['updated_at' => $now, 'primary_leader_id' => $leader->id];
            if (Schema::hasColumn('cms_countries', 'phone') && empty($country->phone) && ! empty($leader->phone)) {
                $patch['phone'] = $leader->phone;
            }
            if (Schema::hasColumn('cms_countries', 'whatsapp_number') && empty($country->whatsapp_number) && ! empty($leader->phone)) {
                $patch['whatsapp_number'] = $leader->phone;
            }
            DB::table('cms_countries')->where('id', $country->id)->update($patch);
            if (empty($leader->country_id)) {
                DB::table('cms_leadership_profiles')->where('id', $leader->id)->update([
                    'country_id' => $country->id,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function restructurePrimaryNavigation(): void
    {
        if (! Schema::hasTable('cms_menus') || ! Schema::hasTable('cms_menu_items')) {
            return;
        }

        $menu = DB::table('cms_menus')->where('slug', 'primary')->first();
        if ($menu === null) {
            $now = now();
            $menuId = DB::table('cms_menus')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => 'Primary Navigation',
                'slug' => 'primary',
                'location' => 'header',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $menu = (object) ['id' => $menuId];
        }

        $now = now();

        // Soft-disable obsolete top-level items (preserve history).
        DB::table('cms_menu_items')
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->whereIn('label', ['Leadership', 'Global Presence', 'Media', 'Counseling', 'Events', 'Blog', 'Gallery', 'Vlog', 'Resources'])
            ->update(['is_active' => false, 'updated_at' => $now]);

        $aboutId = $this->upsertTopLevelItem($menu->id, 'About', '/about', 1, $now);
        $this->upsertChildItem($menu->id, $aboutId, 'Leadership', '/leadership', 0, $now);
        $this->upsertChildItem($menu->id, $aboutId, 'Global Presence', '/global-presence', 1, $now);

        $this->upsertTopLevelItem($menu->id, 'Home', '/', 0, $now);
        $this->upsertTopLevelItem($menu->id, 'Ministries', '/ministries', 2, $now);

        $connectId = $this->upsertTopLevelItem($menu->id, 'Connect', '/connect', 3, $now);
        $connectChildren = [
            ['Counseling', '/counseling', 0],
            ['Events', '/events', 1],
            ['Blog', '/blog', 2],
            ['Gallery', '/gallery', 3],
            ['Vlog', '/vlog', 4],
            ['Resources', '/resources', 5],
        ];
        foreach ($connectChildren as [$label, $url, $order]) {
            $this->upsertChildItem($menu->id, $connectId, $label, $url, $order, $now);
        }

        $this->upsertTopLevelItem($menu->id, 'Contact', '/contact', 4, $now);

        // Ensure only intended top-level items remain active.
        $activeTopLabels = ['Home', 'About', 'Ministries', 'Connect', 'Contact'];
        DB::table('cms_menu_items')
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->whereNotIn('label', $activeTopLabels)
            ->update(['is_active' => false, 'updated_at' => $now]);
    }

    private function upsertTopLevelItem(int $menuId, string $label, string $url, int $sortOrder, $now): int
    {
        $existing = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->where('label', $label)
            ->first();

        if ($existing) {
            DB::table('cms_menu_items')->where('id', $existing->id)->update([
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return (int) $existing->id;
        }

        // Prefer reactivating a soft-deleted / inactive Media row as Connect when renaming.
        if ($label === 'Connect') {
            $media = DB::table('cms_menu_items')
                ->where('menu_id', $menuId)
                ->whereNull('parent_id')
                ->where(function ($q): void {
                    $q->where('label', 'Media')->orWhere('url', '/media');
                })
                ->first();
            if ($media) {
                DB::table('cms_menu_items')->where('id', $media->id)->update([
                    'label' => 'Connect',
                    'url' => '/connect',
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

                return (int) $media->id;
            }
        }

        return (int) DB::table('cms_menu_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'menu_id' => $menuId,
            'parent_id' => null,
            'label' => $label,
            'url' => $url,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertChildItem(int $menuId, int $parentId, string $label, string $url, int $sortOrder, $now): void
    {
        $existing = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->where('parent_id', $parentId)
            ->where('label', $label)
            ->first();

        if ($existing) {
            DB::table('cms_menu_items')->where('id', $existing->id)->update([
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        // Re-parent an existing top-level item with the same label if present.
        $orphan = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->where('label', $label)
            ->where(function ($q) use ($parentId): void {
                $q->whereNull('parent_id')->orWhere('parent_id', '<>', $parentId);
            })
            ->first();

        if ($orphan) {
            DB::table('cms_menu_items')->where('id', $orphan->id)->update([
                'parent_id' => $parentId,
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('cms_menu_items')->insert([
            'uuid' => (string) Str::uuid(),
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'label' => $label,
            'url' => $url,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function updateHomeHeroDefaults(): void
    {
        if (! Schema::hasTable('cms_page_sections')) {
            return;
        }

        $section = DB::table('cms_page_sections')
            ->where('page_slug', 'home')
            ->where('section_key', 'hero')
            ->first();

        if ($section === null) {
            return;
        }

        $content = json_decode((string) $section->content, true);
        if (! is_array($content)) {
            $content = [];
        }

        $ctas = $content['ctas'] ?? null;
        if (is_array($ctas)) {
            foreach ($ctas as $i => $cta) {
                if (! is_array($cta)) {
                    continue;
                }
                $label = strtolower((string) ($cta['label'] ?? ''));
                if (str_contains($label, 'discover') && ($cta['to'] ?? '') === '/about') {
                    $ctas[$i]['to'] = '/ministries';
                }
            }
            $content['ctas'] = $ctas;
        }

        $mediaCta = is_array($content['media_cta'] ?? null) ? $content['media_cta'] : [];
        if (empty($content['story_video_url'])) {
            // Keep empty until admin sets it — expose field for CMS editing.
            $content['story_video_url'] = $content['story_video_url'] ?? '';
        }
        if (empty($mediaCta['href']) || $mediaCta['href'] === '#our-story') {
            $mediaCta['href'] = ! empty($content['story_video_url'])
                ? $content['story_video_url']
                : '#our-story';
            $mediaCta['label'] = $mediaCta['label'] ?? 'Watch Our Story';
            $content['media_cta'] = $mediaCta;
        }

        DB::table('cms_page_sections')->where('id', $section->id)->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
    }

    private function ensureCounsellingWhoIsThisForColumn(): void
    {
        if (! Schema::hasTable('counselling_cases')) {
            return;
        }

        if (! Schema::hasColumn('counselling_cases', 'who_is_this_for')) {
            Schema::table('counselling_cases', function (Blueprint $table): void {
                $table->string('who_is_this_for', 80)->nullable()->after('client_gender');
                $table->index('who_is_this_for');
            });
        }
    }

    private function flushPublicCmsCache(): void
    {
        try {
            app(CmsCacheManager::class)->flushPublic();
        } catch (\Throwable) {
            foreach (['cms:public:site-bootstrap', 'cms:public:home', 'cms:public:sitemap'] as $key) {
                Cache::forget($key);
            }
        }
    }
};
