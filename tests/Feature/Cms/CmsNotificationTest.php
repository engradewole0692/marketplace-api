<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Modules\Cms\Models\CmsAdminNotification;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CmsNotificationTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([RoleSeeder::class, \Database\Seeders\PermissionSeeder::class, RolePermissionSeeder::class, SuperAdminSeeder::class, CmsSeeder::class]);
    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_contact_form_creates_admin_notification(): void
  {
    $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Jane Doe',
      'email' => 'jane@example.com',
      'country' => 'Nigeria',
      'subject' => 'Hello',
      'message' => 'Test message',
    ])->assertCreated();

    $this->assertDatabaseHas('cms_admin_notifications', [
      'user_id' => $this->admin->id,
      'type' => 'form_submission',
    ]);
  }

  public function test_admin_can_fetch_unread_count(): void
  {
    CmsAdminNotification::query()->create([
      'user_id' => $this->admin->id,
      'type' => 'form_submission',
      'title' => 'Test',
      'message' => 'Test notification',
    ]);

    $this->actingAs($this->admin)
      ->getJson('/api/v1/cms/notifications/unread-count')
      ->assertOk()
      ->assertJsonPath('data.count', 1);
  }
}
