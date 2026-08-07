<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Models\CmsTestimonial;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CmsWorkflowTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([
      RoleSeeder::class,
      \Database\Seeders\PermissionSeeder::class,
      RolePermissionSeeder::class,
      CmsSeeder::class,
      SuperAdminSeeder::class,
    ]);

    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_admin_can_view_dashboard_overview(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/cms/dashboard');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'overview' => [
            'pages' => ['total', 'draft', 'review', 'published', 'scheduled', 'archived'],
            'media' => ['total', 'storage_bytes', 'storage_mb'],
            'partners' => ['total', 'active'],
            'testimonials' => ['total', 'active', 'featured'],
            'menus' => ['total', 'active'],
            'seo' => ['total'],
            'recent_edits',
          ],
        ],
      ]);

    $this->assertGreaterThan(0, $response->json('data.overview.pages.total'));
    $this->assertGreaterThan(0, $response->json('data.overview.testimonials.total'));
    $this->assertGreaterThan(0, $response->json('data.overview.menus.total'));
  }

  public function test_admin_can_manage_partners_crud_reorder_and_bulk_update(): void
  {
    $first = $this->actingAs($this->admin)->postJson('/api/v1/cms/partners', [
      'name' => 'Alpha Partner',
      'tier' => 'gold',
      'is_active' => true,
    ])->assertCreated()->json('data.partner.id');

    $second = $this->actingAs($this->admin)->postJson('/api/v1/cms/partners', [
      'name' => 'Beta Partner',
      'tier' => 'silver',
      'is_active' => true,
    ])->assertCreated()->json('data.partner.id');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/partners')
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['data', 'meta']]);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/partners/{$first}", [
        'name' => 'Alpha Partner Updated',
        'is_featured' => true,
      ])
      ->assertOk()
      ->assertJsonPath('data.partner.name', 'Alpha Partner Updated')
      ->assertJsonPath('data.partner.is_featured', true);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/partners/reorder', [
        'ids' => [$second, $first],
      ])
      ->assertOk();

    $this->assertDatabaseHas('cms_partners', [
      'uuid' => $second,
      'sort_order' => 0,
    ]);
    $this->assertDatabaseHas('cms_partners', [
      'uuid' => $first,
      'sort_order' => 1,
    ]);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/partners/bulk-update', [
        'ids' => [$first, $second],
        'is_active' => false,
      ])
      ->assertOk()
      ->assertJsonPath('data.updated', 2);

    $this->assertDatabaseHas('cms_partners', ['uuid' => $first, 'is_active' => false]);
    $this->assertDatabaseHas('cms_partners', ['uuid' => $second, 'is_active' => false]);

    $this->actingAs($this->admin)
      ->deleteJson("/api/v1/cms/partners/{$first}")
      ->assertOk();

    $this->assertSoftDeleted('cms_partners', ['uuid' => $first]);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/partners/bulk-delete', ['ids' => [$second]])
      ->assertOk()
      ->assertJsonPath('data.deleted', 1);

    $this->assertSoftDeleted('cms_partners', ['uuid' => $second]);
  }

  public function test_admin_can_manage_testimonials_crud_and_bulk_operations(): void
  {
    $seeded = CmsTestimonial::query()->where('is_active', true)->orderBy('sort_order')->limit(2)->get();
    $this->assertCount(2, $seeded);

    $created = $this->actingAs($this->admin)->postJson('/api/v1/cms/testimonials', [
      'author_name' => 'Test Author',
      'author_title' => 'CEO',
      'author_location' => 'Lagos, Nigeria',
      'quote' => 'This community changed how I lead.',
      'is_active' => true,
      'is_featured' => false,
    ])->assertCreated()->json('data.testimonial.id');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/testimonials')
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/testimonials/{$created}", [
        'quote' => 'Updated testimonial quote.',
        'is_featured' => true,
      ])
      ->assertOk()
      ->assertJsonPath('data.testimonial.quote', 'Updated testimonial quote.')
      ->assertJsonPath('data.testimonial.is_featured', true);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/testimonials/reorder', [
        'ids' => [$created, $seeded[0]->uuid, $seeded[1]->uuid],
      ])
      ->assertOk();

    $this->assertDatabaseHas('cms_testimonials', [
      'uuid' => $created,
      'sort_order' => 0,
    ]);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/testimonials/bulk-update', [
        'ids' => [$created, $seeded[0]->uuid],
        'is_active' => false,
      ])
      ->assertOk()
      ->assertJsonPath('data.updated', 2);

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/testimonials/bulk-delete', ['ids' => [$created]])
      ->assertOk()
      ->assertJsonPath('data.deleted', 1);

    $this->assertSoftDeleted('cms_testimonials', ['uuid' => $created]);

    $this->actingAs($this->admin)
      ->deleteJson("/api/v1/cms/testimonials/{$seeded[1]->uuid}")
      ->assertOk();

    $this->assertSoftDeleted('cms_testimonials', ['uuid' => $seeded[1]->uuid]);
  }

  public function test_admin_can_list_menus_and_sync_nested_items(): void
  {
    $menu = CmsMenu::query()->where('slug', 'primary')->firstOrFail();

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/menus')
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.menus.0.slug', 'primary');

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/menus/{$menu->uuid}/items", [
        'items' => [
          [
            'label' => 'Home',
            'url' => '/',
            'sort_order' => 0,
            'children' => [
              ['label' => 'Welcome', 'url' => '/welcome', 'sort_order' => 0],
              ['label' => 'Highlights', 'url' => '/highlights', 'sort_order' => 1],
            ],
          ],
          [
            'label' => 'About',
            'url' => '/about',
            'sort_order' => 1,
            'children' => [
              ['label' => 'Our Story', 'url' => '/about/story', 'sort_order' => 0],
            ],
          ],
        ],
      ])
      ->assertOk()
      ->assertJsonPath('data.menu.items.0.label', 'Home')
      ->assertJsonPath('data.menu.items.0.children.0.label', 'Welcome')
      ->assertJsonPath('data.menu.items.1.children.0.label', 'Our Story');

    $this->assertDatabaseHas('cms_menu_items', ['menu_id' => $menu->id, 'label' => 'Welcome']);
    $this->assertDatabaseHas('cms_menu_items', ['menu_id' => $menu->id, 'label' => 'Our Story']);
    $this->assertSoftDeleted('cms_menu_items', ['menu_id' => $menu->id, 'label' => 'Leadership']);
  }

  public function test_admin_can_manage_seo_records(): void
  {
    $created = $this->actingAs($this->admin)->postJson('/api/v1/cms/seo', [
      'path' => '/about',
      'meta_title' => 'About Marketplace Ministers',
      'meta_description' => 'Learn about our global movement.',
      'no_index' => false,
    ])->assertCreated()->json('data.seo.id');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/seo')
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonFragment(['path' => '/about']);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/seo/{$created}", [
        'meta_title' => 'About Us | Marketplace Ministers',
        'meta_description' => 'Updated SEO description.',
        'no_index' => true,
      ])
      ->assertOk()
      ->assertJsonPath('data.seo.meta_title', 'About Us | Marketplace Ministers')
      ->assertJsonPath('data.seo.no_index', true);

    $this->actingAs($this->admin)
      ->deleteJson("/api/v1/cms/seo/{$created}")
      ->assertOk();

    $this->assertSoftDeleted('cms_seo', ['uuid' => $created]);
  }
}
