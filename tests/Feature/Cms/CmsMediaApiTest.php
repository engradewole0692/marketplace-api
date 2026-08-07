<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsMedia;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CmsMediaApiTest extends TestCase
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

  public function test_admin_can_show_media_with_usage_references(): void
  {
    $item = CmsCatalogItem::query()
      ->where('type', 'gallery')
      ->whereNotNull('featured_media_id')
      ->firstOrFail();

    $media = CmsMedia::query()->findOrFail($item->featured_media_id);

    $this->actingAs($this->admin)
      ->getJson("/api/v1/cms/media/{$media->uuid}")
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.media.id', $media->uuid)
      ->assertJsonPath('data.media.usages.0.type', 'catalog_item')
      ->assertJsonPath('data.media.usages.0.id', $item->uuid);
  }

  public function test_admin_can_replace_media_file_even_when_in_use(): void
  {
    Storage::fake('public');

    $item = CmsCatalogItem::query()
      ->where('type', 'gallery')
      ->whereNotNull('featured_media_id')
      ->firstOrFail();

    $media = CmsMedia::query()->findOrFail($item->featured_media_id);
    $originalPath = $media->path;

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/media/{$media->uuid}/replace", [
        'file' => UploadedFile::fake()->image('replaced.jpg', 800, 600),
      ])
      ->assertOk()
      ->assertJsonPath('data.media.file_name', 'replaced.jpg');

    $media = $media->fresh();
    $this->assertNotSame($originalPath, $media->path);
    $this->assertSame($item->fresh()->featured_media_id, $media->id);
  }

  public function test_admin_can_bulk_move_media_between_folders(): void
  {
    Storage::fake('public');

    $sourceFolder = $this->actingAs($this->admin)->postJson('/api/v1/cms/media/folders', [
      'name' => 'Source Folder',
    ])->assertCreated()->json('data.folder.id');

    $targetFolder = $this->actingAs($this->admin)->postJson('/api/v1/cms/media/folders', [
      'name' => 'Target Folder',
    ])->assertCreated()->json('data.folder.id');

    $firstId = $this->actingAs($this->admin)->post('/api/v1/cms/media', [
      'file' => UploadedFile::fake()->image('move-one.jpg', 640, 480),
      'folder_id' => $sourceFolder,
    ])->assertCreated()->json('data.media.id');

    $secondId = $this->actingAs($this->admin)->post('/api/v1/cms/media', [
      'file' => UploadedFile::fake()->image('move-two.jpg', 641, 481),
      'folder_id' => $sourceFolder,
    ])->assertCreated()->json('data.media.id');

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/bulk-move', [
        'media_ids' => [$firstId, $secondId],
        'folder_id' => $targetFolder,
      ])
      ->assertOk()
      ->assertJsonPath('data.moved', 2);

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media?folder_id='.$targetFolder)
      ->assertOk()
      ->assertJsonCount(2, 'data.data');
  }

  public function test_admin_cannot_delete_media_that_is_in_use(): void
  {
    $item = CmsCatalogItem::query()
      ->where('type', 'gallery')
      ->whereNotNull('featured_media_id')
      ->firstOrFail();

    $media = CmsMedia::query()->findOrFail($item->featured_media_id);

    $this->actingAs($this->admin)
      ->deleteJson("/api/v1/cms/media/{$media->uuid}")
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['media']);

    $this->assertDatabaseHas('cms_media', ['uuid' => $media->uuid]);
  }

  public function test_admin_cannot_bulk_delete_media_that_is_in_use(): void
  {
    Storage::fake('public');

    $item = CmsCatalogItem::query()
      ->where('type', 'gallery')
      ->whereNotNull('featured_media_id')
      ->firstOrFail();

    $inUseMedia = CmsMedia::query()->findOrFail($item->featured_media_id);

    $unusedId = $this->actingAs($this->admin)->post('/api/v1/cms/media', [
      'file' => UploadedFile::fake()->image('unused.jpg', 333, 222),
    ])->assertCreated()->json('data.media.id');

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/bulk-delete', [
        'media_ids' => [$inUseMedia->uuid, $unusedId],
      ])
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['media']);

    $this->assertDatabaseHas('cms_media', ['uuid' => $inUseMedia->uuid]);
    $this->assertDatabaseHas('cms_media', ['uuid' => $unusedId]);
  }
}
