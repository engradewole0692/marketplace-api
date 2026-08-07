<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Member;
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

final class ProfilePhotoTest extends TestCase
{
  use RefreshDatabase;

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
  }

  public function test_admin_can_upload_and_delete_user_avatar_via_media_library_pipeline(): void
  {
    $admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();

    $upload = $this->actingAs($admin)->post('/api/v1/iam/users/'.$admin->id.'/avatar', [
      'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
    ]);

    $upload->assertOk();
    $upload->assertJsonPath('data.user.avatar_url', fn ($url) => is_string($url) && $url !== '');
    $this->assertNotNull($admin->fresh()->avatar_media_id);

    $delete = $this->actingAs($admin)->delete('/api/v1/iam/users/'.$admin->id.'/avatar');
    $delete->assertOk();
    $this->assertNull($admin->fresh()->avatar_media_id);
  }

  public function test_admin_can_attach_existing_media_as_user_avatar(): void
  {
    $admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
    $media = CmsMedia::query()->create([
      'name' => 'Avatar media',
      'file_name' => 'avatar.png',
      'disk' => 'public',
      'path' => 'cms/media/avatar.png',
      'mime_type' => 'image/png',
      'size' => 100,
      'created_by' => $admin->id,
      'updated_by' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->putJson('/api/v1/iam/users/'.$admin->id.'/avatar', [
      'media_id' => $media->uuid,
    ]);

    $response->assertOk();
    $this->assertSame($media->id, $admin->fresh()->avatar_media_id);
  }

  public function test_admin_can_upload_member_photo(): void
  {
    $admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
    $member = Member::factory()->create([
      'email' => 'photo-member@example.com',
      'status' => 'under_review',
    ]);

    $response = $this->actingAs($admin)->post('/api/v1/members/'.$member->uuid.'/photo', [
      'photo' => UploadedFile::fake()->image('member.jpg', 240, 240),
    ]);

    $response->assertOk();
    $this->assertNotNull($member->fresh()->photo_media_id);
    $this->assertNotNull($member->fresh()->photoUrl());
  }
}
