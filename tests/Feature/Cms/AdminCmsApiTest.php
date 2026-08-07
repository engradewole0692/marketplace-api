<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMinistry;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdminCmsApiTest extends TestCase
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

  public function test_admin_can_list_countries(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/cms/countries');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_admin_can_update_country_coordinates_and_public_cache_refreshes(): void
  {
    $country = CmsCountry::query()->where('slug', 'nigeria')->firstOrFail();

    $this->getJson('/api/v1/public/countries/nigeria')
      ->assertOk()
      ->assertJsonPath('data.name', 'Nigeria');

    $this->actingAs($this->admin)->putJson("/api/v1/cms/countries/{$country->uuid}", [
      'name' => 'Updated Nigeria',
      'summary' => 'Updated country summary.',
      'latitude' => 57.5,
      'longitude' => 48.25,
      'content' => [
        ...$country->content,
        'contact_email' => 'nigeria@example.com',
      ],
      'is_active' => true,
    ])->assertOk()
      ->assertJsonPath('data.country.name', 'Updated Nigeria')
      ->assertJsonPath('data.country.latitude', 57.5)
      ->assertJsonPath('data.country.longitude', 48.25);

    $this->getJson('/api/v1/public/countries/nigeria')
      ->assertOk()
      ->assertJsonPath('data.name', 'Updated Nigeria')
      ->assertJsonPath('data.summary', 'Updated country summary.')
      ->assertJsonPath('data.content.contact_email', 'nigeria@example.com');

    $this->getJson('/api/v1/public/home')
      ->assertOk()
      ->assertJsonPath('data.countries.0.name', 'Updated Nigeria');
  }

  public function test_admin_can_toggle_and_reorder_countries(): void
  {
    $countries = CmsCountry::query()->whereIn('slug', ['nigeria', 'ghana'])->orderBy('sort_order')->get();
    $first = $countries[0];
    $second = $countries[1];

    $this->actingAs($this->admin)->putJson("/api/v1/cms/countries/{$first->uuid}", [
      'is_active' => false,
    ])->assertOk()
      ->assertJsonPath('data.country.is_active', false);

    $this->getJson('/api/v1/public/countries')
      ->assertOk()
      ->assertJsonMissing(['slug' => $first->slug]);

    $this->actingAs($this->admin)->postJson('/api/v1/cms/countries/reorder', [
      'ids' => [$second->uuid, $first->uuid],
    ])->assertOk();

    $this->assertDatabaseHas('cms_countries', [
      'uuid' => $second->uuid,
      'sort_order' => 1,
    ]);
    $this->assertDatabaseHas('cms_countries', [
      'uuid' => $first->uuid,
      'sort_order' => 2,
    ]);
  }

  public function test_admin_can_upload_country_image(): void
  {
    Storage::fake('public');
    $country = CmsCountry::query()->where('slug', 'nigeria')->firstOrFail();

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/countries/{$country->uuid}/image", [
        'image' => UploadedFile::fake()->image('country.jpg', 1200, 800),
      ])
      ->assertOk()
      ->assertJsonPath('data.country.name', $country->name);

    $this->assertDatabaseHas('cms_media', [
      'file_name' => 'country.jpg',
      'disk' => 'public',
    ]);
    $this->assertNotNull($country->fresh()->hero_media_id);
  }

  public function test_admin_can_list_settings(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/cms/settings');

    $response
      ->assertOk()
      ->assertJsonPath('success', true);
  }

  public function test_admin_can_update_gallery_item_and_public_cache_refreshes(): void
  {
    $item = CmsCatalogItem::query()->where('type', 'gallery')->where('slug', 'lagos-executive-forum')->firstOrFail();

    $this->getJson('/api/v1/public/catalog/gallery')
      ->assertOk()
      ->assertJsonFragment(['slug' => 'lagos-executive-forum']);

    $this->actingAs($this->admin)->putJson("/api/v1/cms/catalog/gallery/{$item->uuid}", [
      'title' => 'Updated Lagos Executive Forum',
      'metadata' => [
        ...$item->metadata,
        'photographer' => 'Updated Media Team',
      ],
    ])->assertOk()
      ->assertJsonPath('data.item.title', 'Updated Lagos Executive Forum')
      ->assertJsonPath('data.item.metadata.photographer', 'Updated Media Team');

    $this->getJson('/api/v1/public/catalog/gallery')
      ->assertOk()
      ->assertJsonFragment(['title' => 'Updated Lagos Executive Forum'])
      ->assertJsonFragment(['photographer' => 'Updated Media Team']);
  }

  public function test_admin_can_toggle_and_reorder_gallery_items(): void
  {
    $items = CmsCatalogItem::query()->where('type', 'gallery')->orderBy('sort_order')->limit(2)->get();
    $first = $items[0];
    $second = $items[1];

    $this->actingAs($this->admin)->putJson("/api/v1/cms/catalog/gallery/{$first->uuid}", [
      'is_active' => false,
    ])->assertOk()
      ->assertJsonPath('data.item.is_active', false);

    $this->getJson('/api/v1/public/catalog/gallery')
      ->assertOk()
      ->assertJsonMissing(['slug' => $first->slug]);

    $this->actingAs($this->admin)->postJson('/api/v1/cms/catalog/gallery/reorder', [
      'ids' => [$second->uuid, $first->uuid],
    ])->assertOk();

    $this->assertDatabaseHas('cms_catalog_items', [
      'uuid' => $second->uuid,
      'sort_order' => 1,
    ]);
    $this->assertDatabaseHas('cms_catalog_items', [
      'uuid' => $first->uuid,
      'sort_order' => 2,
    ]);
  }

  public function test_admin_can_upload_gallery_image(): void
  {
    Storage::fake('public');
    $item = CmsCatalogItem::query()->where('type', 'gallery')->firstOrFail();

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/catalog/gallery/{$item->uuid}/media", [
        'media' => UploadedFile::fake()->image('gallery.jpg', 1200, 800),
      ])
      ->assertOk()
      ->assertJsonPath('data.item.title', $item->title);

    $this->assertDatabaseHas('cms_media', [
      'file_name' => 'gallery.jpg',
      'disk' => 'public',
    ]);
    $this->assertNotNull($item->fresh()->featured_media_id);
  }

  public function test_admin_can_upload_resource_file_and_public_response_uses_file_url(): void
  {
    Storage::fake('public');
    $item = CmsCatalogItem::query()->where('type', 'resource')->where('slug', 'marketplace-ministers-handbook')->firstOrFail();

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/catalog/resource/{$item->uuid}/file", [
        'file' => UploadedFile::fake()->create('handbook.pdf', 128, 'application/pdf'),
      ])
      ->assertOk()
      ->assertJsonPath('data.item.metadata.file_size_bytes', 131072);

    $item = $item->fresh();

    $this->assertDatabaseHas('cms_media', [
      'file_name' => 'handbook.pdf',
      'disk' => 'public',
    ]);
    $this->assertStringContainsString('/storage/', $item->metadata['file_url']);

    $this->getJson('/api/v1/public/catalog/resource')
      ->assertOk()
      ->assertJsonFragment(['slug' => 'marketplace-ministers-handbook'])
      ->assertJsonFragment(['file_url' => $item->metadata['file_url']]);
  }

  public function test_admin_can_list_form_submissions(): void
  {
    $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Jane Doe',
      'email' => 'jane@example.com',
      'country' => 'Nigeria',
      'subject' => 'Test',
      'message' => 'Hello',
    ])->assertCreated();

    $response = $this->actingAs($this->admin)->getJson('/api/v1/cms/form-submissions');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_admin_can_show_form_submission_by_uuid(): void
  {
    $created = $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'UUID Lookup',
      'email' => 'uuid.lookup@example.com',
      'subject' => 'Lookup',
      'message' => 'Show by uuid please',
    ])->assertCreated();

    $uuid = $created->json('data.id');
    $this->assertNotEmpty($uuid);

    $this->actingAs($this->admin)
      ->getJson("/api/v1/cms/form-submissions/{$uuid}")
      ->assertOk()
      ->assertJsonPath('data.submission.id', $uuid)
      ->assertJsonPath('data.submission.submitter_email', 'uuid.lookup@example.com');

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/form-submissions/{$uuid}", [
        'status' => 'processing',
      ])
      ->assertOk()
      ->assertJsonPath('data.submission.status', 'processing');
  }

  public function test_admin_can_archive_and_restore_form_submission(): void
  {
    $created = $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Archive Me',
      'email' => 'archive.me@example.com',
      'subject' => 'Archive',
      'message' => 'Please archive then restore',
    ])->assertCreated();

    $uuid = $created->json('data.id');

    $this->actingAs($this->admin)
      ->deleteJson("/api/v1/cms/form-submissions/{$uuid}")
      ->assertOk();

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/form-submissions?trashed=only')
      ->assertOk()
      ->assertJsonFragment(['id' => $uuid]);

    $this->actingAs($this->admin)
      ->getJson("/api/v1/cms/form-submissions/{$uuid}")
      ->assertOk()
      ->assertJsonPath('data.submission.id', $uuid);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/cms/form-submissions/{$uuid}/restore")
      ->assertOk()
      ->assertJsonPath('data.submission.id', $uuid)
      ->assertJsonPath('data.submission.deleted_at', null);
  }

  public function test_public_volunteer_and_newsletter_unsubscribe_endpoints(): void
  {
    $this->postJson('/api/v1/public/forms/volunteer', [
      'fullName' => 'Volunteer Lead',
      'email' => 'volunteer@example.com',
      'phone' => '+10000000000',
      'country' => 'Nigeria',
      'message' => 'I want to volunteer',
    ])->assertCreated();

    $this->postJson('/api/v1/public/forms/partnership', [
      'fullName' => 'Partner Volunteer',
      'email' => 'partner.volunteer@example.com',
      'phone' => '+10000000001',
      'country' => 'Nigeria',
      'partnerType' => 'Individual',
      'area' => 'volunteer',
      'message' => 'Volunteer via partnership form',
    ])->assertCreated()
      ->assertJsonPath('message', 'Volunteer application received.');

    $this->postJson('/api/v1/public/forms/newsletter/unsubscribe', [
      'email' => 'leave@example.com',
    ])->assertCreated();
  }

  public function test_admin_can_update_ministry_and_public_cache_refreshes(): void
  {
    $ministry = CmsMinistry::query()->where('slug', 'prayer-ministry')->firstOrFail();

    $this->getJson('/api/v1/public/ministries/prayer-ministry')
      ->assertOk()
      ->assertJsonPath('data.name', 'Prayer Ministry');

    $this->actingAs($this->admin)->putJson("/api/v1/cms/ministries/{$ministry->uuid}", [
      'name' => 'Updated Prayer Ministry',
      'summary' => 'Updated public summary.',
      'is_active' => true,
    ])->assertOk()
      ->assertJsonPath('data.ministry.name', 'Updated Prayer Ministry');

    $this->getJson('/api/v1/public/ministries/prayer-ministry')
      ->assertOk()
      ->assertJsonPath('data.name', 'Updated Prayer Ministry')
      ->assertJsonPath('data.summary', 'Updated public summary.');

    $this->getJson('/api/v1/public/home')
      ->assertOk()
      ->assertJsonPath('data.ministries.0.name', 'Updated Prayer Ministry');
  }

  public function test_admin_can_toggle_and_reorder_ministries(): void
  {
    $ministries = CmsMinistry::query()->whereIn('slug', ['prayer-ministry', 'care-ministry'])->orderBy('sort_order')->get();
    $first = $ministries[0];
    $second = $ministries[1];

    $this->actingAs($this->admin)->putJson("/api/v1/cms/ministries/{$first->uuid}", [
      'is_active' => false,
    ])->assertOk()
      ->assertJsonPath('data.ministry.is_active', false);

    $this->getJson('/api/v1/public/ministries')
      ->assertOk()
      ->assertJsonMissing(['slug' => $first->slug]);

    $this->actingAs($this->admin)->postJson('/api/v1/cms/ministries/reorder', [
      'ids' => [$second->uuid, $first->uuid],
    ])->assertOk();

    $this->assertDatabaseHas('cms_ministries', [
      'uuid' => $second->uuid,
      'sort_order' => 1,
    ]);
    $this->assertDatabaseHas('cms_ministries', [
      'uuid' => $first->uuid,
      'sort_order' => 2,
    ]);
  }

  public function test_admin_can_upload_ministry_image(): void
  {
    Storage::fake('public');
    $ministry = CmsMinistry::query()->where('slug', 'prayer-ministry')->firstOrFail();

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/ministries/{$ministry->uuid}/image", [
        'image' => UploadedFile::fake()->image('ministry.jpg', 1200, 800),
      ])
      ->assertOk()
      ->assertJsonPath('data.ministry.name', $ministry->name);

    $this->assertDatabaseHas('cms_media', [
      'file_name' => 'ministry.jpg',
      'disk' => 'public',
    ]);
    $this->assertNotNull($ministry->fresh()->hero_media_id);
  }

  public function test_admin_can_update_leadership_and_public_cache_refreshes(): void
  {
    $profile = CmsLeadershipProfile::query()->orderBy('sort_order')->firstOrFail();

    $this->getJson('/api/v1/public/leadership')
      ->assertOk()
      ->assertJsonPath('data.0.name', $profile->name);

    $this->actingAs($this->admin)->putJson("/api/v1/cms/leadership/{$profile->uuid}", [
      'name' => 'Updated Leader Name',
      'role' => $profile->role,
      'is_active' => true,
    ])->assertOk()
      ->assertJsonPath('data.profile.name', 'Updated Leader Name');

    $this->getJson('/api/v1/public/leadership')
      ->assertOk()
      ->assertJsonPath('data.0.name', 'Updated Leader Name');
  }

  public function test_admin_can_toggle_and_reorder_leadership_profiles(): void
  {
    $profiles = CmsLeadershipProfile::query()->orderBy('sort_order')->limit(2)->get();
    $first = $profiles[0];
    $second = $profiles[1];

    $this->actingAs($this->admin)->putJson("/api/v1/cms/leadership/{$first->uuid}", [
      'is_active' => false,
    ])->assertOk()
      ->assertJsonPath('data.profile.is_active', false);

    $this->getJson('/api/v1/public/leadership')
      ->assertOk()
      ->assertJsonMissing(['name' => $first->name]);

    $this->actingAs($this->admin)->postJson('/api/v1/cms/leadership/reorder', [
      'ids' => [$second->uuid, $first->uuid],
    ])->assertOk();

    $this->assertDatabaseHas('cms_leadership_profiles', [
      'uuid' => $second->uuid,
      'sort_order' => 1,
    ]);
    $this->assertDatabaseHas('cms_leadership_profiles', [
      'uuid' => $first->uuid,
      'sort_order' => 2,
    ]);
  }

  public function test_admin_can_upload_leadership_photo(): void
  {
    Storage::fake('public');
    $profile = CmsLeadershipProfile::query()->orderBy('sort_order')->firstOrFail();

    $this->actingAs($this->admin)
      ->post("/api/v1/cms/leadership/{$profile->uuid}/photo", [
        'photo' => UploadedFile::fake()->image('leader.jpg', 600, 800),
      ])
      ->assertOk()
      ->assertJsonPath('data.profile.name', $profile->name);

    $this->assertDatabaseHas('cms_media', [
      'file_name' => 'leader.jpg',
      'disk' => 'public',
    ]);
    $this->assertNotNull($profile->fresh()->photo_media_id);
  }

  public function test_admin_can_manage_media_library(): void
  {
    Storage::fake('public');

    $folderResponse = $this->actingAs($this->admin)->postJson('/api/v1/cms/media/folders', [
      'name' => 'Campaign Assets',
    ]);

    $folderResponse
      ->assertCreated()
      ->assertJsonPath('data.folder.name', 'Campaign Assets');

    $folderId = $folderResponse->json('data.folder.id');

    $uploadResponse = $this->actingAs($this->admin)->post('/api/v1/cms/media', [
      'file' => UploadedFile::fake()->image('banner.jpg'),
      'folder_id' => $folderId,
    ]);

    $uploadResponse
      ->assertCreated()
      ->assertJsonPath('data.media.file_name', 'banner.jpg');

    $mediaId = $uploadResponse->json('data.media.id');

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/media?folder_id='.$folderId)
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonCount(1, 'data.data');

    $this->actingAs($this->admin)
      ->putJson("/api/v1/cms/media/{$mediaId}", ['name' => 'Homepage Banner'])
      ->assertOk()
      ->assertJsonPath('data.media.name', 'Homepage Banner');

    $this->actingAs($this->admin)
      ->postJson('/api/v1/cms/media/bulk-delete', ['media_ids' => [$mediaId]])
      ->assertOk()
      ->assertJsonPath('data.deleted', 1);
  }
}
