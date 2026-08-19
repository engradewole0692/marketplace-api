<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsSetting;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CmsSyncTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CmsSeeder::class,
      SuperAdminSeeder::class,
    ]);

    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_admin_setting_update_is_reflected_on_public_bootstrap(): void
  {
    Cache::put('cms:public:site-bootstrap', ['settings' => ['site_name' => 'Stale Name'], 'menus' => []], 300);

    $this->actingAs($this->admin)
      ->putJson('/api/v1/cms/settings', [
        'settings' => [[
          'key' => 'site_name',
          'value' => 'Marketplace Ministers Updated',
          'group' => 'general',
          'is_public' => true,
        ]],
      ])
      ->assertOk();

    $this->assertDatabaseHas('cms_settings', [
      'key' => 'site_name',
    ]);

    $bootstrap = $this->getJson('/api/v1/public/site')->assertOk()->json('data.settings');
    $this->assertSame('Marketplace Ministers Updated', $bootstrap['site_name'] ?? null);
  }

  public function test_published_about_section_is_immediately_visible_on_public_page(): void
  {
    $section = CmsPageSection::query()
      ->where('page_slug', 'about')
      ->orderBy('sort_order')
      ->firstOrFail();

    Cache::put("cms:public:page:about", [
      'page' => ['slug' => 'about'],
      'sections' => collect([]),
      'seo' => null,
    ], 300);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/sections/{$section->uuid}", [
        'draft_content' => [
          ...($section->content ?? []),
          'blocks' => [[
            'type' => 'rich_text',
            'title' => 'Sync Test Heading',
            'body' => 'Updated from CMS sync test.',
          ]],
        ],
      ])
      ->assertOk();

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/sections/{$section->uuid}/publish", [
        'change_summary' => 'Sync test publish',
      ])
      ->assertOk();

    $page = $this->getJson('/api/v1/public/pages/about')->assertOk()->json('data.sections.0.content.blocks.0.title');
    $this->assertSame('Sync Test Heading', $page);
  }

  public function test_menu_item_sync_is_reflected_on_public_bootstrap(): void
  {
    $menu = CmsMenu::query()->where('slug', 'primary')->firstOrFail();

    Cache::put('cms:public:site-bootstrap', ['settings' => [], 'menus' => []], 300);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/menus/{$menu->uuid}/items", [
        'items' => [[
          'label' => 'Sync Nav Item',
          'url' => '/sync-test',
          'is_active' => true,
          'sort_order' => 1,
        ]],
      ])
      ->assertOk();

    $menus = $this->getJson('/api/v1/public/site')->assertOk()->json('data.menus');
    $mainNav = collect($menus)->firstWhere('slug', 'primary');
    $this->assertNotNull($mainNav);
    $this->assertSame('Sync Nav Item', $mainNav['items'][0]['label'] ?? null);
  }

  public function test_unauthorized_user_cannot_update_cms_settings(): void
  {
    $this->putJson('/api/v1/cms/settings', [
      'settings' => [[
        'key' => 'site_name',
        'value' => 'Hacked',
        'group' => 'general',
        'is_public' => true,
      ]],
    ])->assertUnauthorized();
  }

  public function test_admin_seo_update_is_reflected_on_public_home(): void
  {
    Cache::put('cms:public:home', ['sections' => [], 'hidden_section_keys' => [], 'seo' => null], 300);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/seo', [
        'path' => '/',
        'meta_title' => 'CMS Homepage Title',
        'meta_description' => 'CMS homepage description from sync test.',
      ])
      ->assertCreated();

    $seo = $this->getJson('/api/v1/public/home')->assertOk()->json('data.seo');
    $this->assertSame('CMS Homepage Title', $seo['meta_title'] ?? null);
    $this->assertSame('CMS homepage description from sync test.', $seo['meta_description'] ?? null);
  }
}
