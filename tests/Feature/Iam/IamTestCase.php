<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class IamTestCase extends TestCase
{
  use RefreshDatabase;

  protected User $admin;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
    ]);

    $this->admin = User::factory()->create();
    $superAdminRole = Role::query()->where('slug', 'super_administrator')->firstOrFail();
    $this->admin->roles()->sync([$superAdminRole->id]);

    Sanctum::actingAs($this->admin);
  }

  protected function memberUser(): User
  {
    $user = User::factory()->create();
    $memberRole = Role::query()->where('slug', 'member')->firstOrFail();
    $user->roles()->sync([$memberRole->id]);

    return $user;
  }
}
