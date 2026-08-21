<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LMS open module access defaults + hero/nav/vlog CMS corrections.
 */
return new class extends Migration
{
    private const VLOG_CHANNEL_URL = 'https://www.youtube.com/channel/UCD7mq-tuAbI-_D-iDp5I2HA';

    private const VLOG_CHANNEL_ID = 'UCD7mq-tuAbI-_D-iDp5I2HA';

    private const HERO_HEADLINE = "Raising Marketplace Ministers\nDisciplining Kingdom Leaders\nAdvancing God's Agenda";

    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('lms_schools') && Schema::hasColumn('lms_schools', 'sequential_progression')) {
            // Open curriculum by default; admins can re-enable sequential locking per school.
            DB::table('lms_schools')->update([
                'sequential_progression' => false,
                'updated_at' => $now,
            ]);
        }

        $this->upsertSetting('vlog', 'vlog_youtube_channel_url', self::VLOG_CHANNEL_URL, true, $now);
        $this->upsertSetting('vlog', 'vlog_youtube_channel_id', self::VLOG_CHANNEL_ID, true, $now);

        $this->updateHeroHeadline($now);
        $this->reorderConnectNav($now);

        Cache::forget('cms:public:site-bootstrap');
        Cache::forget('cms:public:home');
        Cache::forget('cms:public:vlog-youtube-feed');
        Cache::forget('cms:public:page:home');
    }

    public function down(): void
    {
        // Non-destructive: leave open-access defaults and CMS content in place.
    }

    private function upsertSetting(string $group, string $key, string $value, bool $isPublic, $now): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        $encoded = json_encode($value);
        $existing = DB::table('cms_settings')->where('key', $key)->first();

        if ($existing) {
            DB::table('cms_settings')->where('id', $existing->id)->update([
                'group' => $group,
                'value' => $encoded,
                'type' => 'string',
                'is_public' => $isPublic,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('cms_settings')->insert([
            'group' => $group,
            'key' => $key,
            'value' => $encoded,
            'type' => 'string',
            'is_public' => $isPublic,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function updateHeroHeadline($now): void
    {
        if (! Schema::hasTable('cms_page_sections')) {
            return;
        }

        $section = DB::table('cms_page_sections')
            ->where('page_slug', 'home')
            ->where('section_key', 'hero')
            ->first();

        if (! $section) {
            return;
        }

        $content = json_decode((string) $section->content, true);
        if (! is_array($content)) {
            $content = [];
        }

        $content['headline'] = self::HERO_HEADLINE;

        DB::table('cms_page_sections')->where('id', $section->id)->update([
            'content' => json_encode($content),
            'updated_at' => $now,
        ]);
    }

    private function reorderConnectNav($now): void
    {
        if (! Schema::hasTable('cms_menus') || ! Schema::hasTable('cms_menu_items')) {
            return;
        }

        $menu = DB::table('cms_menus')->where('slug', 'primary')->first();
        if (! $menu) {
            return;
        }

        $connect = DB::table('cms_menu_items')
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->where('label', 'Connect')
            ->first();

        if (! $connect) {
            return;
        }

        $order = [
            'Counseling' => 0,
            'Events' => 1,
            'Blog' => 2,
            'Vlog' => 3,
            'Gallery' => 4,
            'Resources' => 5,
        ];

        foreach ($order as $label => $sortOrder) {
            DB::table('cms_menu_items')
                ->where('menu_id', $menu->id)
                ->where('parent_id', $connect->id)
                ->where('label', $label)
                ->update([
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
        }
    }
};
