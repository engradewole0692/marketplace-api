<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CmsMediaEnterpriseTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');

    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      SuperAdminSeeder::class,
    ]);

    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_upload_deduplicates_identical_files(): void
  {
    $file = UploadedFile::fake()->image('hero.jpg', 800, 600);

    $first = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => $file])
      ->assertCreated()
      ->json('data.media.id');

    $second = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => $file])
      ->assertOk()
      ->assertJsonPath('data.deduplicated', true)
      ->json('data.media.id');

    $this->assertSame($first, $second);
    $this->assertSame(1, CmsMedia::query()->count());
  }

  public function test_recycle_bin_restore_and_force_delete(): void
  {
    $id = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => UploadedFile::fake()->image('bin.jpg', 640, 480)])
      ->assertCreated()
      ->json('data.media.id');

    $media = CmsMedia::query()->where('uuid', $id)->firstOrFail();
    $this->assertTrue(Storage::disk('public')->exists($media->path));

    $this->actingAs($this->admin)->deleteJson("/api/v1/cms/media/{$id}")->assertOk();
    $this->assertSoftDeleted('cms_media', ['uuid' => $id]);
    $this->assertTrue(Storage::disk('public')->exists($media->path));

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media?trashed=only')
      ->assertOk()
      ->assertJsonPath('data.data.0.id', $id);

    $this->actingAs($this->admin)->postJson("/api/v1/cms/media/{$id}/restore")->assertOk();
    $this->assertDatabaseHas('cms_media', ['uuid' => $id, 'deleted_at' => null]);

    $this->actingAs($this->admin)->deleteJson("/api/v1/cms/media/{$id}")->assertOk();
    $this->actingAs($this->admin)->deleteJson("/api/v1/cms/media/{$id}/force")->assertOk();
    $this->assertDatabaseMissing('cms_media', ['uuid' => $id]);
  }

  public function test_duplicate_optimize_resize_and_statistics(): void
  {
    $id = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => UploadedFile::fake()->image('wide.jpg', 1600, 900)])
      ->assertCreated()
      ->json('data.media.id');

    $copyId = $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/media/{$id}/duplicate")
      ->assertCreated()
      ->json('data.media.id');

    $this->assertNotSame($id, $copyId);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/media/{$id}/optimize")
      ->assertOk()
      ->assertJsonPath('data.media.is_optimized', true);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/media/{$id}/resize", [
        'max_width' => 800,
        'replace' => true,
      ])
      ->assertOk();

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media/statistics')
      ->assertOk()
      ->assertJsonPath('data.statistics.total', 2);

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/media/{$id}", [
        'alt_text' => 'Wide landscape',
        'credits' => 'Photo desk',
        'copyright' => '© MPM',
        'tags' => ['homepage', 'hero'],
        'focal_x' => 0.4,
        'focal_y' => 0.35,
        'caption' => 'Summit stage',
        'description' => 'Primary stage photo',
      ])
      ->assertOk()
      ->assertJsonPath('data.media.credits', 'Photo desk')
      ->assertJsonPath('data.media.tags.0', 'homepage');
  }

  public function test_bulk_restore_and_detectors(): void
  {
    $first = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => UploadedFile::fake()->image('a.jpg', 500, 400)])
      ->assertCreated()
      ->json('data.media.id');

    $second = $this->actingAs($this->admin)
      ->post('/api/v1/cms/media', ['file' => UploadedFile::fake()->image('b.jpg', 501, 401)])
      ->assertCreated()
      ->json('data.media.id');

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/bulk-delete', ['media_ids' => [$first, $second]])
      ->assertOk();

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/bulk-restore', ['media_ids' => [$first, $second]])
      ->assertOk()
      ->assertJsonPath('data.restored', 2);

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media/unused')
      ->assertOk()
      ->assertJsonStructure(['data' => ['items']]);

    $media = CmsMedia::query()->where('uuid', $first)->firstOrFail();
    Storage::disk('public')->delete($media->path);

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media/broken')
      ->assertOk()
      ->assertJsonFragment(['id' => $first, 'reason' => 'missing_original']);
  }

  public function test_nested_folder_tree_and_bulk_upload(): void
  {
    $parent = $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/folders', ['name' => 'Campaigns'])
      ->assertCreated()
      ->json('data.folder.id');

    $child = $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/folders', [
        'name' => '2026 Summit',
        'parent_id' => $parent,
      ])
      ->assertCreated()
      ->json('data.folder.id');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media/folders')
      ->assertOk()
      ->assertJsonPath('data.folders.0.id', $parent)
      ->assertJsonPath('data.folders.0.children.0.id', $child);

    $this->actingAs($this->admin)
      ->post('/api/v1/cms/media/bulk-upload', [
        'files' => [
          UploadedFile::fake()->image('one.jpg', 300, 300),
          UploadedFile::fake()->image('two.jpg', 301, 301),
        ],
        'folder_id' => $child,
      ])
      ->assertCreated()
      ->assertJsonCount(2, 'data.items');
  }
}
