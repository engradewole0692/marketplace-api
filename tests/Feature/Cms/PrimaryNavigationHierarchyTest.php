<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Iam\IamTestCase;

final class PrimaryNavigationHierarchyTest extends IamTestCase
{
  public function test_public_bootstrap_returns_connect_with_ordered_children(): void
  {
    $this->ensureCanonicalPrimaryNavigation();
    Cache::forget('cms:public:site-bootstrap');

    $this->assertPublicPrimaryHierarchy(
      $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus'),
    );
  }

  public function test_cached_bootstrap_retains_connect_children(): void
  {
    $this->ensureCanonicalPrimaryNavigation();
    Cache::forget('cms:public:site-bootstrap');

    $first = $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus');
    $second = $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus');

    $this->assertPublicPrimaryHierarchy($first);
    $this->assertPublicPrimaryHierarchy($second);
  }

  public function test_repair_migration_restores_missing_connect_hierarchy(): void
  {
    $menu = CmsMenu::query()->where('slug', 'primary')->firstOrFail();

    CmsMenuItem::query()
      ->where('menu_id', $menu->id)
      ->where('label', 'Connect')
      ->each(function (CmsMenuItem $item): void {
        $item->delete();
      });

    CmsMenuItem::withTrashed()
      ->where('menu_id', $menu->id)
      ->whereIn('label', ['Counseling', 'Events', 'Blog', 'Vlog', 'Gallery', 'Resources', 'Business Review'])
      ->update([
        'parent_id' => null,
        'is_active' => false,
        'deleted_at' => now(),
      ]);

    Cache::put('cms:public:site-bootstrap', ['settings' => [], 'menus' => []], 300);

    $migration = require base_path('database/migrations/2026_08_21_160000_repair_connect_navigation_hierarchy.php');
    $migration->up();

    $this->assertPublicPrimaryHierarchy(
      $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus'),
    );
  }

  public function test_repair_migration_creates_connect_when_production_omitted_it(): void
  {
    $menu = CmsMenu::query()->updateOrCreate(
      ['slug' => 'primary'],
      ['name' => 'Primary Navigation', 'location' => 'header', 'is_active' => true],
    );

    CmsMenuItem::query()->where('menu_id', $menu->id)->delete();

    $about = CmsMenuItem::query()->create([
      'uuid' => (string) Str::uuid(),
      'menu_id' => $menu->id,
      'parent_id' => null,
      'label' => 'About',
      'url' => '/about',
      'is_active' => true,
      'sort_order' => 1,
    ]);

    CmsMenuItem::query()->create([
      'uuid' => (string) Str::uuid(),
      'menu_id' => $menu->id,
      'parent_id' => $about->id,
      'label' => 'Leadership',
      'url' => '/leadership',
      'is_active' => true,
      'sort_order' => 0,
    ]);
    CmsMenuItem::query()->create([
      'uuid' => (string) Str::uuid(),
      'menu_id' => $menu->id,
      'parent_id' => $about->id,
      'label' => 'Global Presence',
      'url' => '/global-presence',
      'is_active' => true,
      'sort_order' => 1,
    ]);

    foreach ([['Home', '/', 0], ['Ministries', '/ministries', 3], ['Contact', '/contact', 7]] as [$label, $url, $sort]) {
      CmsMenuItem::query()->create([
        'uuid' => (string) Str::uuid(),
        'menu_id' => $menu->id,
        'parent_id' => null,
        'label' => $label,
        'url' => $url,
        'is_active' => true,
        'sort_order' => $sort,
      ]);
    }

    foreach (['Counseling' => '/counseling', 'Events' => '/events', 'Blog' => '/blog', 'Gallery' => '/gallery', 'Vlog' => '/vlog', 'Resources' => '/resources', 'Business Review' => '/business-review'] as $label => $url) {
      CmsMenuItem::query()->create([
        'uuid' => (string) Str::uuid(),
        'menu_id' => $menu->id,
        'parent_id' => null,
        'label' => $label,
        'url' => $url,
        'is_active' => false,
        'sort_order' => 9,
      ]);
    }

    Cache::put('cms:public:site-bootstrap', ['settings' => [], 'menus' => []], 300);

    $migration = require base_path('database/migrations/2026_08_21_160000_repair_connect_navigation_hierarchy.php');
    $migration->up();

    $this->assertPublicPrimaryHierarchy(
      $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus'),
    );
  }

  public function test_admin_menu_sync_with_connect_children_flushes_public_bootstrap(): void
  {
    $menu = CmsMenu::query()->where('slug', 'primary')->firstOrFail();
    Cache::put('cms:public:site-bootstrap', ['settings' => [], 'menus' => []], 300);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/menus/{$menu->uuid}/items", [
        'items' => [
          ['label' => 'Home', 'url' => '/', 'is_active' => true, 'sort_order' => 0],
          [
            'label' => 'About',
            'url' => '/about',
            'is_active' => true,
            'sort_order' => 1,
            'children' => [
              ['label' => 'Leadership', 'url' => '/leadership', 'is_active' => true, 'sort_order' => 0],
              ['label' => 'Global Presence', 'url' => '/global-presence', 'is_active' => true, 'sort_order' => 1],
            ],
          ],
          ['label' => 'Ministries', 'url' => '/ministries', 'is_active' => true, 'sort_order' => 2],
          [
            'label' => 'Connect',
            'url' => '/connect',
            'is_active' => true,
            'sort_order' => 3,
            'children' => [
              ['label' => 'Counseling', 'url' => '/counseling', 'is_active' => true, 'sort_order' => 0],
              ['label' => 'Events', 'url' => '/events', 'is_active' => true, 'sort_order' => 1],
              ['label' => 'Blog', 'url' => '/blog', 'is_active' => true, 'sort_order' => 2],
              ['label' => 'Vlog', 'url' => '/vlog', 'is_active' => true, 'sort_order' => 3],
              ['label' => 'Gallery', 'url' => '/gallery', 'is_active' => true, 'sort_order' => 4],
              ['label' => 'Resources', 'url' => '/resources', 'is_active' => true, 'sort_order' => 5],
              ['label' => 'Business Review', 'url' => '/business-review', 'is_active' => true, 'sort_order' => 6],
            ],
          ],
          ['label' => 'Contact', 'url' => '/contact', 'is_active' => true, 'sort_order' => 4],
        ],
      ])
      ->assertOk();

    $this->assertPublicPrimaryHierarchy(
      $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus'),
    );
  }

  /**
   * @param  mixed  $menus
   */
  private function assertPublicPrimaryHierarchy(mixed $menus): void
  {
    $this->assertIsArray($menus);
    $primary = collect($menus)->firstWhere('slug', 'primary');
    $this->assertIsArray($primary);

    $labels = collect($primary['items'] ?? [])->pluck('label')->values()->all();
    $this->assertSame(['Home', 'About', 'Ministries', 'Connect', 'Contact'], $labels);

    $about = collect($primary['items'])->firstWhere('label', 'About');
    $this->assertSame(
      ['Leadership', 'Global Presence'],
      collect($about['children'] ?? [])->pluck('label')->values()->all(),
    );

    $connect = collect($primary['items'])->firstWhere('label', 'Connect');
    $this->assertNotNull($connect);
    $this->assertSame('/connect', $connect['url'] ?? null);
    $this->assertIsArray($connect['children'] ?? null);
    $this->assertSame(
      ['Counseling', 'Events', 'Blog', 'Vlog', 'Gallery', 'Resources', 'Business Review'],
      collect($connect['children'])->pluck('label')->values()->all(),
    );
    $this->assertSame(
      ['/counseling', '/events', '/blog', '/vlog', '/gallery', '/resources', '/business-review'],
      collect($connect['children'])->pluck('url')->values()->all(),
    );
  }

  private function ensureCanonicalPrimaryNavigation(): void
  {
    $menu = CmsMenu::query()->updateOrCreate(
      ['slug' => 'primary'],
      ['name' => 'Primary Navigation', 'location' => 'header', 'is_active' => true],
    );

    CmsMenuItem::query()->where('menu_id', $menu->id)->delete();

    $defs = [
      ['Home', '/', 0, []],
      ['About', '/about', 1, [
        ['Leadership', '/leadership', 0],
        ['Global Presence', '/global-presence', 1],
      ]],
      ['Ministries', '/ministries', 2, []],
      ['Connect', '/connect', 3, [
        ['Counseling', '/counseling', 0],
        ['Events', '/events', 1],
        ['Blog', '/blog', 2],
        ['Vlog', '/vlog', 3],
        ['Gallery', '/gallery', 4],
        ['Resources', '/resources', 5],
        ['Business Review', '/business-review', 6],
      ]],
      ['Contact', '/contact', 4, []],
    ];

    foreach ($defs as [$label, $url, $sort, $children]) {
      $parent = CmsMenuItem::query()->create([
        'uuid' => (string) Str::uuid(),
        'menu_id' => $menu->id,
        'parent_id' => null,
        'label' => $label,
        'url' => $url,
        'is_active' => true,
        'sort_order' => $sort,
      ]);

      foreach ($children as [$childLabel, $childUrl, $childSort]) {
        CmsMenuItem::query()->create([
          'uuid' => (string) Str::uuid(),
          'menu_id' => $menu->id,
          'parent_id' => $parent->id,
          'label' => $childLabel,
          'url' => $childUrl,
          'is_active' => true,
          'sort_order' => $childSort,
        ]);
      }
    }
  }
}
