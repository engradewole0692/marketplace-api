<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Non-destructive: Business Review fields/workflow, Connect nav child,
 * Global Presence CMS counters, homepage hero headline copy.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: int}> */
    private const CONNECT_CHILD = ['Business Review', '/business-review', 6];

    /** @var list<array{label: string, value: string}> */
    private const PRESENCE_STATS = [
        ['label' => 'Countries', 'value' => '14+'],
        ['label' => 'Cities', 'value' => '32+'],
        ['label' => 'Volunteers', 'value' => '20+'],
        ['label' => 'Mission Projects', 'value' => '48+'],
        ['label' => 'Lead Coordinators', 'value' => '10+'],
    ];

    public function up(): void
    {
        $this->extendBusinessReviewTables();
        $this->remapBusinessReviewStatuses();
        $this->ensureConnectBusinessReviewItem();
        $this->seedGlobalPresenceStats();
        $this->updateHomeHeroHeadline();
        $this->ensureExportPermission();

        Cache::forget('cms:public:site-bootstrap');
        Cache::forget('cms:public:home');
        Cache::forget('cms:public:page:global-presence');
        Cache::forget('cms:public:page:home');
        Cache::forget('cms:public:sitemap');
    }

    public function down(): void
    {
        if (Schema::hasTable('business_review_status_histories')) {
            Schema::dropIfExists('business_review_status_histories');
        }
    }

    private function extendBusinessReviewTables(): void
    {
        if (! Schema::hasTable('business_reviews')) {
            return;
        }

        Schema::table('business_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('business_reviews', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('uuid')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('business_reviews', 'first_name')) {
                $table->string('first_name')->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('business_reviews', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('business_reviews', 'country')) {
                $table->string('country')->nullable()->after('business_location');
            }
            if (! Schema::hasColumn('business_reviews', 'state_province')) {
                $table->string('state_province')->nullable()->after('country');
            }
            if (! Schema::hasColumn('business_reviews', 'years_in_operation')) {
                $table->unsignedSmallInteger('years_in_operation')->nullable()->after('business_stage');
            }
            if (! Schema::hasColumn('business_reviews', 'website_url')) {
                $table->string('website_url', 500)->nullable()->after('website_social');
            }
            if (! Schema::hasColumn('business_reviews', 'social_links')) {
                $table->text('social_links')->nullable()->after('website_url');
            }
            if (! Schema::hasColumn('business_reviews', 'advice_areas')) {
                $table->text('advice_areas')->nullable()->after('main_challenges');
            }
            if (! Schema::hasColumn('business_reviews', 'employee_count')) {
                $table->unsignedInteger('employee_count')->nullable()->after('years_in_operation');
            }
            if (! Schema::hasColumn('business_reviews', 'referral_source')) {
                $table->string('referral_source')->nullable()->after('additional_info');
            }
        });

        if (! Schema::hasTable('business_review_status_histories')) {
            Schema::create('business_review_status_histories', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('business_review_id')->constrained('business_reviews')->cascadeOnDelete();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->index(['business_review_id', 'created_at']);
            });
        }
    }

    private function remapBusinessReviewStatuses(): void
    {
        if (! Schema::hasTable('business_reviews')) {
            return;
        }

        $map = [
            'contacted' => 'information_requested',
            'scheduled' => 'information_requested',
            'in_progress' => 'under_review',
            'completed' => 'review_completed',
            'declined' => 'closed',
        ];

        foreach ($map as $from => $to) {
            DB::table('business_reviews')->where('status', $from)->update([
                'status' => $to,
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureConnectBusinessReviewItem(): void
    {
        if (! Schema::hasTable('cms_menus') || ! Schema::hasTable('cms_menu_items')) {
            return;
        }

        $menu = DB::table('cms_menus')->where('slug', 'primary')->first();
        if ($menu === null) {
            return;
        }

        $connect = DB::table('cms_menu_items')
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->where('label', 'Connect')
            ->whereNull('deleted_at')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($connect === null) {
            return;
        }

        [$label, $url, $sort] = self::CONNECT_CHILD;
        $now = now();

        $existing = DB::table('cms_menu_items')
            ->where('menu_id', $menu->id)
            ->where(function ($q) use ($connect, $label, $url): void {
                $q->where(function ($inner) use ($connect, $label): void {
                    $inner->where('parent_id', $connect->id)->where('label', $label);
                })->orWhere('url', $url);
            })
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();

        if ($existing) {
            DB::table('cms_menu_items')->where('id', $existing->id)->update([
                'parent_id' => $connect->id,
                'label' => $label,
                'url' => $url,
                'sort_order' => $sort,
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('cms_menu_items')->insert([
            'uuid' => (string) Str::uuid(),
            'menu_id' => $menu->id,
            'parent_id' => $connect->id,
            'label' => $label,
            'url' => $url,
            'is_active' => true,
            'sort_order' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    private function seedGlobalPresenceStats(): void
    {
        if (! Schema::hasTable('cms_page_sections')) {
            return;
        }

        $section = DB::table('cms_page_sections')
            ->where('page_slug', 'global-presence')
            ->where('section_key', 'main')
            ->whereNull('deleted_at')
            ->first();

        $block = [
            'type' => 'presence_stats',
            'eyebrow' => 'Impact',
            'title' => 'A tribe across nations.',
            'items' => self::PRESENCE_STATS,
        ];

        $now = now();

        if ($section === null) {
            $row = [
                'uuid' => (string) Str::uuid(),
                'page_slug' => 'global-presence',
                'section_key' => 'main',
                'section_type' => 'content',
                'title' => 'Global Presence',
                'content' => json_encode(['blocks' => [$block]]),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('cms_page_sections', 'status')) {
                $row['status'] = 'published';
            }
            if (Schema::hasColumn('cms_page_sections', 'published_at')) {
                $row['published_at'] = $now;
            }
            DB::table('cms_page_sections')->insert($row);

            return;
        }

        $content = json_decode((string) $section->content, true);
        if (! is_array($content)) {
            $content = [];
        }
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
        $blocks = array_values(array_filter(
            $blocks,
            static fn ($item): bool => ! is_array($item) || ($item['type'] ?? '') !== 'presence_stats',
        ));
        array_unshift($blocks, $block);
        $content['blocks'] = $blocks;

        DB::table('cms_page_sections')->where('id', $section->id)->update([
            'content' => json_encode($content),
            'is_active' => true,
            'updated_at' => $now,
        ]);
    }

    private function updateHomeHeroHeadline(): void
    {
        if (! Schema::hasTable('cms_page_sections')) {
            return;
        }

        $section = DB::table('cms_page_sections')
            ->where('page_slug', 'home')
            ->where('section_key', 'hero')
            ->whereNull('deleted_at')
            ->first();

        if ($section === null) {
            return;
        }

        $content = json_decode((string) $section->content, true);
        if (! is_array($content)) {
            $content = [];
        }

        $content['headline'] = "Raising Marketplace Ministers\nDiscipling Kingdom Leaders\nAdvancing God's Agenda";

        DB::table('cms_page_sections')->where('id', $section->id)->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
    }

    private function ensureExportPermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $exists = DB::table('permissions')->where('slug', 'business-review.export')->exists();
        if ($exists) {
            return;
        }

        $permissionId = DB::table('permissions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Export Business Reviews',
            'slug' => 'business-review.export',
            'module' => 'community',
            'group' => 'business-review',
            'description' => 'Export Faith & Works business review submissions.',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roles = DB::table('roles')
            ->whereIn('slug', ['super_administrator', 'administrator'])
            ->pluck('id');

        foreach ($roles as $roleId) {
            $already = DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();
            if (! $already) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
