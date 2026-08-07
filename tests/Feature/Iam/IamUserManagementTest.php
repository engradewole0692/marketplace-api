<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

final class IamUserManagementTest extends IamTestCase
{
  public function test_users_index_requires_permission(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/iam/users')
      ->assertForbidden();
  }

  public function test_super_admin_can_list_users(): void
  {
    User::factory()->count(2)->create();

    $response = $this->getJson('/api/v1/iam/users');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'data' => [['id', 'email', 'status']],
          'meta' => ['current_page', 'total'],
        ],
      ]);
  }

  public function test_super_admin_can_create_user(): void
  {
    $role = \App\Models\Role::query()->where('slug', 'member')->firstOrFail();

    $response = $this->postJson('/api/v1/iam/users', [
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'email' => 'jane.doe@example.com',
      'password' => 'Password123!@#',
      'status' => 'active',
      'role_ids' => [$role->id],
    ]);

    $response
      ->assertCreated()
      ->assertJsonPath('data.user.email', 'jane.doe@example.com');

    $this->assertDatabaseHas('users', ['email' => 'jane.doe@example.com']);
    $this->assertDatabaseHas('iam_audit_logs', ['event_type' => 'user_created']);
  }

  public function test_super_admin_can_update_and_soft_delete_user(): void
  {
    $user = User::factory()->create(['email' => 'update-me@example.com']);

    $this->putJson("/api/v1/iam/users/{$user->id}", [
      'first_name' => 'Updated',
      'status' => 'inactive',
    ])
      ->assertOk()
      ->assertJsonPath('data.user.first_name', 'Updated');

    $this->deleteJson("/api/v1/iam/users/{$user->id}")
      ->assertOk();

    $this->assertSoftDeleted('users', ['id' => $user->id]);
  }

  public function test_super_admin_can_restore_user(): void
  {
    $user = User::factory()->create();
    $user->delete();

    $this->postJson("/api/v1/iam/users/{$user->id}/restore")
      ->assertOk()
      ->assertJsonPath('data.user.id', $user->id);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
  }

  public function test_bulk_activate_users(): void
  {
    $users = User::factory()->count(2)->create(['status' => 'inactive']);

    $response = $this->postJson('/api/v1/iam/users/bulk', [
      'action' => 'activate',
      'user_ids' => $users->pluck('id')->all(),
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('data.affected', 2);

    foreach ($users as $user) {
      $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
    }
  }

  public function test_login_returns_permissions_for_super_admin(): void
  {
    $this->postJson('/api/v1/auth/logout');

    $response = $this->postJson('/api/v1/auth/login', [
      'email' => $this->admin->email,
      'password' => 'Password123!@#',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['user', 'permissions']]);

    $permissions = $response->json('data.permissions');
    $this->assertIsArray($permissions);
    $this->assertContains('admin.access', $permissions);
    $this->assertContains('users.view', $permissions);
  }
}
