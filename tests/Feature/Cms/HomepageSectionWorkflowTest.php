<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsPageSection;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HomepageSectionWorkflowTest extends TestCase
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

    CmsPageSection::query()
      ->where('page_slug', 'home')
      ->whereNull('published_at')
      ->update([
        'status' => 'published',
        'published_at' => now(),
      ]);
  }

  public function test_admin_can_save_draft_without_removing_live_homepage_section(): void
  {
    $section = CmsPageSection::query()
      ->where('page_slug', 'home')
      ->where('section_key', 'hero')
      ->firstOrFail();

    $liveHeadline = $section->content['headline'] ?? null;

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/sections/{$section->uuid}", [
        'draft_content' => [
          ...($section->content ?? []),
          'headline' => 'Draft only headline',
        ],
        'status' => 'draft',
      ])
      ->assertOk()
      ->assertJsonPath('data.section.status', 'draft')
      ->assertJsonPath('data.section.draft_content.headline', 'Draft only headline');

    $section->refresh();
    $this->assertSame($liveHeadline, $section->content['headline'] ?? null);
    $this->assertSame('Draft only headline', $section->draft_content['headline'] ?? null);

    Cache::forget('cms:public:home');

    $home = $this->getJson('/api/v1/public/home')->assertOk()->json('data.sections');
    $hero = collect($home)->firstWhere('section_key', 'hero');

    $this->assertNotNull($hero);
    $this->assertSame($liveHeadline, $hero['content']['headline'] ?? null);
  }

  public function test_admin_can_publish_restore_and_reorder_homepage_sections(): void
  {
    $section = CmsPageSection::query()
      ->where('page_slug', 'home')
      ->where('section_key', 'statistics')
      ->firstOrFail();

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/sections/{$section->uuid}", [
        'draft_content' => [
          ...($section->content ?? []),
          'headline' => 'Published stats headline',
        ],
      ])
      ->assertOk();

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/sections/{$section->uuid}/submit-review")
      ->assertOk()
      ->assertJsonPath('data.section.status', 'review');

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/sections/{$section->uuid}/publish", [
        'change_summary' => 'Homepage stats update',
      ])
      ->assertOk()
      ->assertJsonPath('data.section.status', 'published')
      ->assertJsonPath('data.section.content.headline', 'Published stats headline');

    $versionId = $this->actingAs($this->admin)
      ->getJson("/api/v1/cms/sections/{$section->uuid}/versions")
      ->assertOk()
      ->json('data.section.versions.0.id');

    $this->assertNotEmpty($versionId);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/sections/{$section->uuid}/versions/{$versionId}/restore")
      ->assertOk()
      ->assertJsonPath('data.section.status', 'draft')
      ->assertJsonPath('data.section.draft_content.headline', 'Published stats headline');

    $hero = CmsPageSection::query()->where('section_key', 'hero')->firstOrFail();
    $stats = CmsPageSection::query()->where('section_key', 'statistics')->firstOrFail();

    $this->actingAs($this->admin)
      ->putJson('/api/v1/cms/sections/reorder', [
        'sections' => [
          ['id' => $stats->uuid, 'sort_order' => 1],
          ['id' => $hero->uuid, 'sort_order' => 2],
        ],
      ])
      ->assertOk();

    $this->assertSame(1, $stats->fresh()->sort_order);
    $this->assertSame(2, $hero->fresh()->sort_order);
  }

  public function test_inactive_homepage_section_is_hidden_from_public_api(): void
  {
    $section = CmsPageSection::query()
      ->where('page_slug', 'home')
      ->where('section_key', 'newsletter')
      ->firstOrFail();

    $section->update(['is_active' => false]);
    Cache::forget('cms:public:home');

    $response = $this->getJson('/api/v1/public/home')->assertOk();
    $keys = collect($response->json('data.sections'))->pluck('section_key')->all();

    $this->assertNotContains('newsletter', $keys);
    $this->assertContains('hero', $keys);
    $this->assertContains('newsletter', $response->json('data.hidden_section_keys'));
  }

  public function test_missing_homepage_section_is_not_listed_as_hidden(): void
  {
    CmsPageSection::query()
      ->where('page_slug', 'home')
      ->where('section_key', 'about_intro')
      ->delete();

    Cache::forget('cms:public:home');

    $response = $this->getJson('/api/v1/public/home')->assertOk();

    $this->assertNotContains('about_intro', $response->json('data.hidden_section_keys') ?? []);
  }
}
