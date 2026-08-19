<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SETTINGS = [
        // Podcast
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
        // YouVersion
        [
            'group' => 'media',
            'key' => 'youversion_url',
            'value' => 'https://www.bible.com/organizations/da5c986c-6fe1-473c-9994-afed7012043f?utm_source=yvapp&utm_medium=share&utm_content=partner-page',
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
        // YouTube
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
        // Vlog YouTube RSS
        [
            'group' => 'vlog',
            'key' => 'vlog_youtube_channel_id',
            'value' => '',
            'type' => 'string',
            'is_public' => false,
        ],
        [
            'group' => 'vlog',
            'key' => 'vlog_youtube_channel_url',
            'value' => 'https://www.youtube.com/@thetribeofmarketplaceministers',
            'type' => 'string',
            'is_public' => true,
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SETTINGS as $setting) {
            $existing = DB::table('cms_settings')->where('key', $setting['key'])->first();
            if ($existing) {
                continue;
            }

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
        }
    }

    public function down(): void
    {
        $keys = array_column(self::SETTINGS, 'key');
        DB::table('cms_settings')->whereIn('key', $keys)->delete();
    }
};
