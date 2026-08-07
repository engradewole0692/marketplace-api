<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Models\CmsAuditLog;
use App\Modules\Cms\Models\CmsPage;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CmsPageAdminTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([RoleSeeder::class, \Database\Seeders\PermissionSeeder::class, RolePermissionSeeder::class, SuperAdminSeeder::class]);
    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_admin_can_create_page_with_version_history(): void
  {
    $response = $this->actingAs($this->admin)->postJson('/api/v1/cms/pages', [
      'title' => 'About Us',
      'slug' => 'about',
      'status' => PageStatus::Published->value,
      'hero_title' => 'About Marketplace Ministers',
      'blocks' => [['type' => 'rich_text', 'content' => 'Our story.']],
      'change_summary' => 'Initial publish',
    ]);

    $response->assertCreated()->assertJsonPath('success', true);
    $this->assertDatabaseHas('cms_pages', ['slug' => 'about', 'status' => 'published']);
    $this->assertDatabaseHas('cms_page_versions', ['change_summary' => 'Initial publish']);
  }

  public function test_public_page_endpoint_returns_published_page(): void
  {
    $this->actingAs($this->admin)->postJson('/api/v1/cms/pages', [
      'title' => 'Counseling',
      'slug' => 'counseling',
      'status' => PageStatus::Published->value,
      'blocks' => [['type' => 'features', 'items' => []]],
    ])->assertCreated();

    $this->getJson('/api/v1/public/pages/counseling')
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.page.slug', 'counseling');
  }

  public function test_admin_can_publish_unpublish_and_archive_page_with_audit_trail(): void
  {
    $pageId = $this->actingAs($this->admin)->postJson('/api/v1/cms/pages', [
      'title' => 'Draft Page',
      'slug' => 'draft-page',
      'status' => PageStatus::Draft->value,
      'blocks' => [['type' => 'rich_text', 'content' => 'Draft content.']],
    ])->assertCreated()->json('data.page.id');

    $page = CmsPage::query()->where('uuid', $pageId)->firstOrFail();

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$pageId}/publish")
      ->assertOk()
      ->assertJsonPath('data.page.status', PageStatus::Published->value);

    $this->assertDatabaseHas('cms_audit_logs', [
      'entity_type' => 'page',
      'entity_id' => $page->id,
      'event_type' => CmsAuditEventType::Published->value,
    ]);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$pageId}/unpublish")
      ->assertOk()
      ->assertJsonPath('data.page.status', PageStatus::Draft->value);

    $this->assertDatabaseHas('cms_audit_logs', [
      'entity_type' => 'page',
      'entity_id' => $page->id,
      'event_type' => CmsAuditEventType::Updated->value,
    ]);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$pageId}/publish")
      ->assertOk();

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$pageId}/archive")
      ->assertOk()
      ->assertJsonPath('data.page.status', PageStatus::Archived->value);

    $this->assertDatabaseHas('cms_audit_logs', [
      'entity_type' => 'page',
      'entity_id' => $page->id,
      'event_type' => CmsAuditEventType::Archived->value,
    ]);
  }

  public function test_admin_can_duplicate_page_with_sections(): void
  {
    $this->seed(CmsSeeder::class);

    $source = CmsPage::query()->where('slug', 'about')->firstOrFail();
    $sourceUuid = $source->uuid;

    $response = $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$sourceUuid}/duplicate")
      ->assertCreated()
      ->assertJsonPath('data.page.title', 'About (Copy)')
      ->assertJsonPath('data.page.status', PageStatus::Draft->value);

    $duplicateSlug = $response->json('data.page.slug');
    $this->assertSame('about-copy', $duplicateSlug);

    $this->assertDatabaseHas('cms_pages', [
      'slug' => $duplicateSlug,
      'status' => PageStatus::Draft->value,
      'hero_title' => $source->hero_title,
    ]);

    $this->assertDatabaseHas('cms_page_sections', [
      'page_slug' => $duplicateSlug,
      'section_key' => 'main',
    ]);

    $this->assertDatabaseHas('cms_page_versions', [
      'change_summary' => 'Duplicated from about',
    ]);

    $this->assertDatabaseHas('cms_audit_logs', [
      'entity_type' => 'page',
      'event_type' => CmsAuditEventType::Created->value,
    ]);
  }

  public function test_admin_can_restore_page_version_and_record_audit_event(): void
  {
    $pageId = $this->actingAs($this->admin)->postJson('/api/v1/cms/pages', [
      'title' => 'Versioned Page',
      'slug' => 'versioned-page',
      'status' => PageStatus::Draft->value,
      'hero_title' => 'Original Hero',
      'blocks' => [['type' => 'rich_text', 'content' => 'Original content.']],
      'change_summary' => 'Initial version',
    ])->assertCreated()->json('data.page.id');

    $page = CmsPage::query()->where('uuid', $pageId)->firstOrFail();

    $versions = $this->actingAs($this->admin)
      ->getJson("/api/v1/cms/pages/{$pageId}/versions")
      ->assertOk()
      ->json('data');

    $originalVersionId = $versions[0]['id'];

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/pages/{$pageId}", [
        'title' => 'Updated Page Title',
        'hero_title' => 'Updated Hero',
        'blocks' => [['type' => 'rich_text', 'content' => 'Updated content.']],
        'change_summary' => 'Second version',
      ])
      ->assertOk()
      ->assertJsonPath('data.page.title', 'Updated Page Title');

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/pages/{$pageId}/versions/{$originalVersionId}/restore")
      ->assertOk()
      ->assertJsonPath('data.page.title', 'Versioned Page')
      ->assertJsonPath('data.page.hero_title', 'Original Hero');

    $this->assertDatabaseHas('cms_audit_logs', [
      'entity_type' => 'page',
      'entity_id' => $page->id,
      'event_type' => CmsAuditEventType::Restored->value,
    ]);

    $this->assertGreaterThan(
      1,
      CmsAuditLog::query()->where('entity_type', 'page')->where('entity_id', $page->id)->count(),
    );
  }
}
