<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CmsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardSearchTest extends TestCase
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

  public function test_unauthenticated_search_receives_401(): void
  {
    $this->getJson('/api/v1/dashboard/search?q=test')->assertUnauthorized();
  }

  public function test_admin_can_search_across_modules(): void
  {
    Member::factory()->create([
      'first_name' => 'Workspace',
      'last_name' => 'Searcher',
      'email' => 'workspace.searcher@example.com',
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/search?q=Workspace');

    $response->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.query', 'Workspace')
      ->assertJsonStructure([
        'data' => [
          'query',
          'groups' => [
            ['module', 'label', 'items' => [['id', 'title', 'href', 'type']]],
          ],
        ],
      ]);

    $groups = $response->json('data.groups');
    $this->assertNotEmpty($groups);
    $this->assertSame('members', $groups[0]['module']);
  }

  public function test_short_query_returns_empty_groups(): void
  {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/dashboard/search?q=a');

    $response->assertStatus(422);
  }

  public function test_user_without_admin_access_receives_403(): void
  {
    $memberRole = Role::query()->where('slug', 'member')->firstOrFail();
    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$memberRole->id]);

    $this->actingAs($user)->getJson('/api/v1/dashboard/search?q=test')->assertForbidden();
  }
}
