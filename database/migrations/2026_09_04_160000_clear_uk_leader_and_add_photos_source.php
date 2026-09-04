<?php

declare(strict_types=1);

use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent production corrections:
 * - United Kingdom has no country leader until CMS assigns one
 * - Register the additional Google Photos shared album source
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->clearUnitedKingdomLeader();
        $this->ensurePhotosAlbum('https://photos.app.goo.gl/F8LE4BRrUU53mTJ59');
        $this->flushCaches();
    }

    public function down(): void
    {
        // Non-destructive: keep the cleared leader and extra gallery source.
    }

    private function clearUnitedKingdomLeader(): void
    {
        if (! Schema::hasTable('cms_countries')) {
            return;
        }

        $uk = DB::table('cms_countries')->whereIn('slug', ['united-kingdom', 'uk'])->first();
        if ($uk === null) {
            return;
        }

        $content = $this->decodeContent($uk->content ?? null);
        $content['leader'] = '';
        if (isset($content['leader_slug'])) {
            $content['leader_slug'] = '';
        }
        if (isset($content['leadership_team']) && is_array($content['leadership_team'])) {
            $content['leadership_team'] = array_values(array_filter(
                $content['leadership_team'],
                static function ($member): bool {
                    if (! is_array($member)) {
                        $name = is_string($member) ? strtolower($member) : '';

                        return ! self::isBlockedLeaderName($name);
                    }
                    $name = strtolower((string) ($member['name'] ?? ''));
                    $slug = strtolower((string) ($member['slug'] ?? ''));

                    return ! self::isBlockedLeaderName($name) && ! self::isBlockedLeaderSlug($slug);
                },
            ));
        }

        $patch = [
            'content' => json_encode($content),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('cms_countries', 'primary_leader_id')) {
            $patch['primary_leader_id'] = null;
        }

        DB::table('cms_countries')->where('id', $uk->id)->update($patch);
    }

    private function ensurePhotosAlbum(string $sourceUrl): void
    {
        if (! Schema::hasTable('cms_gallery_sources')) {
            return;
        }

        $existing = DB::table('cms_gallery_sources')->where('source_url', $sourceUrl)->first();
        if ($existing !== null) {
            return;
        }

        $now = now();
        $remoteId = (string) Str::afterLast($sourceUrl, '/');
        DB::table('cms_gallery_sources')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Google Photos album C',
            'provider' => 'google_photos',
            'source_url' => $sourceUrl,
            'remote_id' => $remoteId,
            'access_status' => 'needs_oauth',
            'is_enabled' => true,
            'imported_count' => 0,
            'skipped_count' => 0,
            'duplicate_count' => 0,
            'failed_count' => 0,
            'metadata' => json_encode([
                'classification' => 'google_photos_shared_album',
                'api' => 'photoslibrary_v1',
                'drive_compatible' => false,
                'requires' => 'OAuth 2.0 of the album owner with photoslibrary.readonly. Shared photos.app.goo.gl links are not Photos Library album IDs.',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function isBlockedLeaderName(string $name): bool
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return in_array($normalized, ['yemi akins', 'yemiya kings', 'yemiya akins'], true);
    }

    private static function isBlockedLeaderSlug(string $slug): bool
    {
        return in_array($slug, ['yemi-akins', 'yemiya-kings'], true);
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
        Cache::forget('cms:public:page:global-presence');

        if (app()->bound(CmsCacheManager::class)) {
            app(CmsCacheManager::class)->flushPublic();
        }
    }
};
