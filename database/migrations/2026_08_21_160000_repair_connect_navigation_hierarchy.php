<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Repair primary navigation: ensure Connect exists with its children.
 *
 * Production bootstrap was returning Home/About/Ministries/Contact only —
 * Connect (and therefore its submenu) was missing from active CMS items.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: int}> */
    private const CONNECT_CHILDREN = [
        ['Counseling', '/counseling', 0],
        ['Events', '/events', 1],
        ['Blog', '/blog', 2],
        ['Vlog', '/vlog', 3],
        ['Gallery', '/gallery', 4],
        ['Resources', '/resources', 5],
        ['Business Review', '/business-review', 6],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cms_menus') || ! Schema::hasTable('cms_menu_items')) {
            return;
        }

        $menu = DB::table('cms_menus')->where('slug', 'primary')->first();
        if ($menu === null) {
            return;
        }

        $menuId = (int) $menu->id;
        $now = now();

        $homeId = $this->ensureTopLevel($menuId, 'Home', '/', 0, $now);
        $aboutId = $this->ensureTopLevel($menuId, 'About', '/about', 1, $now);
        $ministriesId = $this->ensureTopLevel($menuId, 'Ministries', '/ministries', 2, $now);
        $connectId = $this->ensureTopLevel($menuId, 'Connect', '/connect', 3, $now);
        $contactId = $this->ensureTopLevel($menuId, 'Contact', '/contact', 4, $now);

        $this->ensureChild($menuId, $aboutId, 'Leadership', '/leadership', 0, $now);
        $this->ensureChild($menuId, $aboutId, 'Global Presence', '/global-presence', 1, $now);

        foreach (self::CONNECT_CHILDREN as [$label, $url, $sortOrder]) {
            $this->ensureChild($menuId, $connectId, $label, $url, $sortOrder, $now);
        }

        $canonicalIds = [$homeId, $aboutId, $ministriesId, $connectId, $contactId];
        $this->deactivateDuplicateTopLevel($menuId, $canonicalIds, $now);

        Cache::forget('cms:public:site-bootstrap');
        Cache::forget('cms:public:home');
        Cache::forget('cms:public:sitemap');
    }

    public function down(): void
    {
        // Non-destructive repair — do not remove Connect.
    }

    private function ensureTopLevel(int $menuId, string $label, string $url, int $sortOrder, $now): int
    {
        $existing = $this->findPreferredItem($menuId, $label, null);

        if ($existing) {
            DB::table('cms_menu_items')->where('id', $existing->id)->update([
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return (int) $existing->id;
        }

        // Revive Media /media as Connect when present.
        $media = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->where(function ($q): void {
                $q->where('label', 'Media')->orWhere('url', '/media');
            })
            ->orderBy('id')
            ->first();

        if ($media) {
            DB::table('cms_menu_items')->where('id', $media->id)->update([
                'label' => $label,
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return (int) $media->id;
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
            'deleted_at' => null,
        ]);
    }

    private function ensureChild(
        int $menuId,
        int $parentId,
        string $label,
        string $url,
        int $sortOrder,
        $now,
    ): void {
        $underParent = $this->findPreferredItem($menuId, $label, $parentId);

        if ($underParent) {
            DB::table('cms_menu_items')->where('id', $underParent->id)->update([
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        $orphan = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->where('label', $label)
            ->where(function ($q) use ($parentId): void {
                $q->whereNull('parent_id')->orWhere('parent_id', '<>', $parentId);
            })
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($orphan) {
            DB::table('cms_menu_items')->where('id', $orphan->id)->update([
                'parent_id' => $parentId,
                'url' => $url,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'deleted_at' => null,
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
            'deleted_at' => null,
        ]);
    }

    private function findPreferredItem(int $menuId, string $label, ?int $parentId): ?object
    {
        $query = DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->where('label', $label);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        return $query
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $canonicalIds
     */
    private function deactivateDuplicateTopLevel(int $menuId, array $canonicalIds, $now): void
    {
        DB::table('cms_menu_items')
            ->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->whereNotIn('id', $canonicalIds)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
    }
};
