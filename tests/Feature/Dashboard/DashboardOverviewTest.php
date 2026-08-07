<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardOverviewTest extends TestCase
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

    app(DashboardMetricsService::class)->forgetCache();
  }

  public function test_unauthenticated_request_receives_401(): void
  {
    $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
  }

  public function test_admin_can_view_overview_with_expected_keys(): void
  {
    Member::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/overview');

    $response->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'generated_at',
          'membership' => [
            'total', 'new_30_days', 'pending', 'active', 'inactive', 'suspended',
            'growth_percent_30d', 'recent_registrations',
          ],
          'cms' => [
            'pages' => ['total', 'draft', 'review', 'published', 'scheduled', 'archived'],
            'media' => ['total', 'storage_bytes', 'storage_mb'],
            'partners' => ['total', 'active'],
            'testimonials' => ['total', 'active', 'featured'],
            'menus' => ['total', 'active'],
            'seo' => ['total', 'coverage_percent'],
            'form_submissions' => ['total', 'new_30_days'],
            'recently_edited_pages',
            'recently_published_pages',
          ],
          'users' => [
            'total', 'administrators', 'editors', 'recent_logins',
            'role_distribution', 'permission_count', 'failed_authorization_attempts',
          ],
          'activity',
          'activity_meta' => ['total_available', 'limit', 'offset'],
          'health' => [
            'application', 'environment', 'version', 'php_version', 'laravel_version',
            'database' => ['driver', 'status'],
            'cache_driver', 'queue_driver', 'mail_driver',
            'storage' => ['media_bytes', 'media_mb', 'disk_free_bytes', 'disk_free_mb'],
            'last_deployment_at', 'health_endpoint_status',
          ],
          'charts' => [
            'member_growth', 'cms_publishing', 'media_uploads', 'form_submissions', 'audit_activity',
          ],
          'events' => [
            'module_installed', 'total_events', 'published_events', 'upcoming_events_count', 'upcoming_events',
            'registration_count', 'registrations_by_status', 'checked_in_count', 'attended_count',
            'new_registrations_30_days',
          ],
          'learning' => [
            'module_installed',
          ],
          'donations' => [
            'module_installed',
          ],
          'notifications' => ['module', 'unread_count_endpoint'],
        ],
      ]);

    $response->assertJsonPath('data.membership.total', 3)
      ->assertJsonPath('data.users.failed_authorization_attempts', null)
      ->assertJsonPath('data.events.module_installed', true)
      ->assertJsonPath('data.notifications.unread_count_endpoint', '/api/v1/cms/notifications/unread-count');

    $this->assertCount(12, $response->json('data.charts.member_growth'));
    $this->assertCount(30, $response->json('data.charts.audit_activity'));
  }

  public function test_activity_feed_endpoint_returns_paginated_items(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/activity?limit=5&offset=0');

    $response->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'activity',
          'activity_meta' => ['total_available', 'limit', 'offset'],
        ],
      ])
      ->assertJsonPath('data.activity_meta.limit', 5)
      ->assertJsonPath('data.activity_meta.offset', 0);
  }

  public function test_activity_feed_limit_is_capped_at_fifty(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/activity?limit=500');

    $response->assertOk()->assertJsonPath('data.activity_meta.limit', 50);
  }

  public function test_overview_is_cached_across_calls(): void
  {
    $first = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/overview');
    $first->assertOk();
    $firstGeneratedAt = $first->json('data.generated_at');

    $second = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/overview');
    $second->assertOk();

    $this->assertSame($firstGeneratedAt, $second->json('data.generated_at'));
  }

  public function test_user_without_admin_access_permission_receives_403(): void
  {
    $memberRole = Role::query()->where('slug', 'member')->firstOrFail();

    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$memberRole->id]);

    $this->actingAs($user)->getJson('/api/v1/dashboard/overview')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/dashboard/activity')->assertForbidden();
  }
}
