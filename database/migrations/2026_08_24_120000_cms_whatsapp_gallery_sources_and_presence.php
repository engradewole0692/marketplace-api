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
 * Public-site corrections that extend existing CMS tables:
 * - Organization WhatsApp setting
 * - Vlog YouTube handle URL
 * - Gallery remote sources (Drive / Photos)
 * - Country leadership (USA / UK / Kenya / Rwanda)
 * - About page: deactivate Journey / Why Marketplace Ministers
 * - Footer Media → Connect
 */
return new class extends Migration
{
    private const ORG_WHATSAPP = '+11434567897';

    private const VLOG_HANDLE_URL = 'https://www.youtube.com/@themarketplaceministers';

    public function up(): void
    {
        $this->createGallerySourcesTable();
        $this->seedGallerySources();
        $this->upsertSetting('contact', 'contact.whatsapp', self::ORG_WHATSAPP, true);
        $this->upsertSetting('vlog', 'vlog_youtube_channel_url', self::VLOG_HANDLE_URL, true);
        $this->correctCountryLeadership();
        $this->deactivateAboutJourneyAndWhy();
        $this->rewriteFooterMediaToConnect();
        $this->flushCaches();
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_gallery_sources');
    }

    private function createGallerySourcesTable(): void
    {
        if (Schema::hasTable('cms_gallery_sources')) {
            return;
        }

        Schema::create('cms_gallery_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('provider', 40);
            $table->string('source_url', 1000);
            $table->string('remote_id', 191)->nullable();
            $table->string('access_status', 40)->default('pending');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['provider', 'is_enabled']);
        });
    }

    private function seedGallerySources(): void
    {
        if (! Schema::hasTable('cms_gallery_sources')) {
            return;
        }

        $now = now();
        $rows = [
            [
                'name' => 'Google Drive gallery folder',
                'provider' => 'google_drive',
                'source_url' => 'https://drive.google.com/drive/folders/1T9Ef3sQkTwL5iIYiADK4j1By8-17AEyg',
                'remote_id' => '1T9Ef3sQkTwL5iIYiADK4j1By8-17AEyg',
                'access_status' => 'pending_credentials',
                'metadata' => [
                    'classification' => 'google_drive',
                    'api' => 'drive_v3',
                    'requires' => 'GOOGLE_DRIVE_API_KEY (public folder) or GOOGLE_SERVICE_ACCOUNT_JSON (private folder shared with the service account)',
                ],
            ],
            [
                'name' => 'Google Photos album A',
                'provider' => 'google_photos',
                'source_url' => 'https://photos.app.goo.gl/mhF5hvrQS3mqEygk6',
                'remote_id' => 'mhF5hvrQS3mqEygk6',
                'access_status' => 'needs_oauth',
                'metadata' => [
                    'classification' => 'google_photos_shared_album',
                    'api' => 'photoslibrary_v1',
                    'drive_compatible' => false,
                    'requires' => 'OAuth 2.0 of the album owner with photoslibrary.readonly. Shared photos.app.goo.gl links are not Photos Library album IDs and cannot be synced with a Drive API key.',
                ],
            ],
            [
                'name' => 'Google Photos album B',
                'provider' => 'google_photos',
                'source_url' => 'https://photos.app.goo.gl/eZ3a9yjoLiTaG3w58',
                'remote_id' => 'eZ3a9yjoLiTaG3w58',
                'access_status' => 'needs_oauth',
                'metadata' => [
                    'classification' => 'google_photos_shared_album',
                    'api' => 'photoslibrary_v1',
                    'drive_compatible' => false,
                    'requires' => 'OAuth 2.0 of the album owner with photoslibrary.readonly. Shared photos.app.goo.gl links are not Photos Library album IDs and cannot be synced with a Drive API key.',
                ],
            ],
        ];

        foreach ($rows as $row) {
            $existing = DB::table('cms_gallery_sources')->where('source_url', $row['source_url'])->first();
            $payload = [
                'name' => $row['name'],
                'provider' => $row['provider'],
                'remote_id' => $row['remote_id'],
                'access_status' => $row['access_status'],
                'is_enabled' => true,
                'metadata' => json_encode($row['metadata']),
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            if ($existing) {
                DB::table('cms_gallery_sources')->where('id', $existing->id)->update($payload);

                continue;
            }

            DB::table('cms_gallery_sources')->insert([
                'uuid' => (string) Str::uuid(),
                'source_url' => $row['source_url'],
                'imported_count' => 0,
                'skipped_count' => 0,
                'duplicate_count' => 0,
                'failed_count' => 0,
                'created_at' => $now,
                ...$payload,
            ]);
        }
    }

    private function upsertSetting(string $group, string $key, string $value, bool $isPublic): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        $now = now();
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

        $row = [
            'group' => $group,
            'key' => $key,
            'value' => $encoded,
            'type' => 'string',
            'is_public' => $isPublic,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('cms_settings', 'uuid')) {
            $row['uuid'] = (string) Str::uuid();
        }

        DB::table('cms_settings')->insert($row);
    }

    private function correctCountryLeadership(): void
    {
        if (! Schema::hasTable('cms_countries')) {
            return;
        }

        $now = now();
        $damolaId = Schema::hasTable('cms_leadership_profiles')
            ? DB::table('cms_leadership_profiles')->where('slug', 'damola-adelakun')->value('id')
            : null;
        $stephenId = Schema::hasTable('cms_leadership_profiles')
            ? DB::table('cms_leadership_profiles')->where('slug', 'stephen-nyaega')->value('id')
            : null;

        $usa = DB::table('cms_countries')->where('slug', 'usa')->first();
        if ($usa) {
            $content = $this->decodeContent($usa->content ?? null);
            $content['leader'] = 'Damola Adelakun';
            $patch = [
                'content' => json_encode($content),
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('cms_countries', 'primary_leader_id') && $damolaId) {
                $patch['primary_leader_id'] = $damolaId;
            }
            DB::table('cms_countries')->where('id', $usa->id)->update($patch);
        }

        $uk = DB::table('cms_countries')->whereIn('slug', ['united-kingdom', 'uk'])->first();
        if ($uk) {
            $content = $this->decodeContent($uk->content ?? null);
            $content['leader'] = '';
            if (isset($content['leadership_team']) && is_array($content['leadership_team'])) {
                $content['leadership_team'] = array_values(array_filter(
                    $content['leadership_team'],
                    static function ($member): bool {
                        if (! is_array($member)) {
                            return true;
                        }
                        $name = strtolower((string) ($member['name'] ?? ''));
                        $slug = strtolower((string) ($member['slug'] ?? ''));

                        return ! str_contains($name, 'yemi akins') && $slug !== 'yemi-akins';
                    },
                ));
            }
            $patch = [
                'content' => json_encode($content),
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('cms_countries', 'primary_leader_id')) {
                $patch['primary_leader_id'] = null;
            }
            DB::table('cms_countries')->where('id', $uk->id)->update($patch);
        }

        $kenya = DB::table('cms_countries')->where('slug', 'kenya')->first();
        if ($kenya && $stephenId && Schema::hasColumn('cms_countries', 'primary_leader_id')) {
            $content = $this->decodeContent($kenya->content ?? null);
            $content['leader'] = 'Stephen Nyaega';
            DB::table('cms_countries')->where('id', $kenya->id)->update([
                'primary_leader_id' => $stephenId,
                'content' => json_encode($content),
                'updated_at' => $now,
            ]);
        }

        $this->ensureRwanda();
    }

    private function ensureRwanda(): void
    {
        if (! Schema::hasTable('cms_countries')) {
            return;
        }
        if (DB::table('cms_countries')->where('slug', 'rwanda')->exists()) {
            return;
        }
        if (DB::table('cms_countries')->count() === 0) {
            return;
        }

        $now = now();
        $row = [
            'uuid' => (string) Str::uuid(),
            'name' => 'Rwanda',
            'slug' => 'rwanda',
            'region' => 'East Africa',
            'summary' => 'Marketplace ministers gathering and deploying across Rwanda.',
            'content' => json_encode([
                'status' => 'Active',
                'leader' => '',
                'members' => '',
                'meeting' => '',
            ]),
            'is_active' => true,
            'sort_order' => ((int) DB::table('cms_countries')->max('sort_order')) + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('cms_countries', 'code')) {
            $row['code'] = 'RW';
        }
        if (Schema::hasColumn('cms_countries', 'flag_emoji')) {
            $row['flag_emoji'] = '🇷🇼';
        }

        DB::table('cms_countries')->insert($row);
    }

    private function deactivateAboutJourneyAndWhy(): void
    {
        if (Schema::hasTable('cms_pages')) {
            $page = DB::table('cms_pages')->where('slug', 'about')->first();
            if ($page && isset($page->blocks)) {
                $blocks = json_decode((string) $page->blocks, true);
                if (is_array($blocks)) {
                    $blocks = array_values(array_filter($blocks, static function ($block): bool {
                        if (! is_array($block)) {
                            return true;
                        }
                        $type = (string) ($block['type'] ?? '');
                        $eyebrow = (string) ($block['eyebrow'] ?? '');

                        if ($type === 'journey') {
                            return false;
                        }

                        return ! ($type === 'features' && str_contains($eyebrow, 'Why Marketplace Ministers'));
                    }));
                    DB::table('cms_pages')->where('id', $page->id)->update([
                        'blocks' => json_encode($blocks),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (! Schema::hasTable('cms_page_sections')) {
            return;
        }

        $sections = DB::table('cms_page_sections')
            ->where('page_slug', 'about')
            ->whereNull('deleted_at')
            ->get();

        foreach ($sections as $section) {
            $content = json_decode((string) $section->content, true);
            if (! is_array($content)) {
                continue;
            }
            $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
            $filtered = array_values(array_filter($blocks, static function ($block): bool {
                if (! is_array($block)) {
                    return true;
                }
                $type = (string) ($block['type'] ?? '');
                $eyebrow = (string) ($block['eyebrow'] ?? '');
                if ($type === 'journey') {
                    return false;
                }

                return ! ($type === 'features' && str_contains($eyebrow, 'Why Marketplace Ministers'));
            }));
            if (count($filtered) !== count($blocks)) {
                $content['blocks'] = $filtered;
                DB::table('cms_page_sections')->where('id', $section->id)->update([
                    'content' => json_encode($content),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function rewriteFooterMediaToConnect(): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        $setting = DB::table('cms_settings')->where('key', 'navigation.footer_columns')->first();
        if ($setting === null) {
            return;
        }

        $columns = json_decode((string) $setting->value, true);
        if (! is_array($columns)) {
            return;
        }
        $changed = false;
        array_walk_recursive($columns, static function (&$value, $key) use (&$changed): void {
            if ($key === 'to' && $value === '/media') {
                $value = '/connect';
                $changed = true;
            }
            if ($key === 'label' && $value === 'Media Center') {
                $value = 'Connect';
                $changed = true;
            }
        });
        if ($changed) {
            DB::table('cms_settings')->where('id', $setting->id)->update([
                'value' => json_encode($columns),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function flushCaches(): void
    {
        Cache::forget('cms:public:site-bootstrap');
        Cache::forget('cms:public:home');
        Cache::forget('cms:public:vlog-youtube-feed');
        Cache::forget('cms:public:page:about');
        Cache::forget('cms:public:page:global-presence');
        Cache::forget('cms:public:page:home');
        Cache::forget('cms:public:sitemap');

        if (app()->bound(CmsCacheManager::class)) {
            app(CmsCacheManager::class)->flushPublic();
        }
    }
};
