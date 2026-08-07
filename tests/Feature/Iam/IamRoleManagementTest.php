<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

final class IamRoleManagementTest extends IamTestCase
{
  public function test_roles_index_returns_paginated_roles(): void
  {
    $response = $this->getJson('/api/v1/iam/roles');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_super_admin_can_create_and_update_role(): void
  {
    $permission = \App\Models\Permission::query()->where('slug', 'users.view')->firstOrFail();

    $create = $this->postJson('/api/v1/iam/roles', [
      'name' => 'Content Editor',
      'description' => 'Can edit CMS content',
      'permission_ids' => [$permission->id],
    ]);

    $create
      ->assertCreated()
      ->assertJsonPath('data.role.name', 'Content Editor');

    $roleId = $create->json('data.role.id');

    $this->putJson("/api/v1/iam/roles/{$roleId}", [
      'name' => 'Content Manager',
    ])
      ->assertOk()
      ->assertJsonPath('data.role.name', 'Content Manager');
  }

  public function test_super_admin_can_clone_role(): void
  {
    $role = Role::query()->where('slug', 'instructor')->firstOrFail();

    $response = $this->postJson("/api/v1/iam/roles/{$role->id}/clone", [
      'name' => 'Instructor Copy',
    ]);

    $response
      ->assertCreated()
      ->assertJsonPath('data.role.name', 'Instructor Copy');

    $this->assertDatabaseHas('roles', ['name' => 'Instructor Copy']);
  }

  public function test_system_role_cannot_be_deleted(): void
  {
    $role = Role::query()->where('slug', 'super_administrator')->firstOrFail();

    $this->deleteJson("/api/v1/iam/roles/{$role->id}")
      ->assertStatus(403);
  }

  public function test_custom_role_can_be_deleted(): void
  {
    $role = Role::query()->create([
      'name' => 'Temporary Role',
      'slug' => 'temporary_role',
      'guard_name' => 'web',
      'is_system' => false,
    ]);

    $this->deleteJson("/api/v1/iam/roles/{$role->id}")
      ->assertOk();

    $this->assertSoftDeleted('roles', ['id' => $role->id]);
  }

  public function test_member_cannot_manage_roles(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/iam/roles')->assertForbidden();
    $this->postJson('/api/v1/iam/roles', ['name' => 'Blocked'])->assertForbidden();
  }
}
