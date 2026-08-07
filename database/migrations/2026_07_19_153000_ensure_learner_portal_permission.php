<?php

declare(strict_types=1);

use App\Enums\AuthGuardName;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Iam\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensure public learners can access /learn after registration.
 * Some environments seeded roles before learner.portal existed in the catalog.
 */
return new class extends Migration
{
  public function up(): void
  {
    $meta = collect(PermissionCatalog::all())->firstWhere('slug', 'learner.portal');

    $permission = Permission::query()->updateOrCreate(
      ['slug' => 'learner.portal'],
      [
        'name' => $meta['name'] ?? 'Learner Portal Access',
        'module' => $meta['module'] ?? 'learning',
        'group' => $meta['group'] ?? 'portal',
        'description' => $meta['description'] ?? 'Access the public learner portal.',
        'is_system' => true,
      ],
    );

    $role = Role::query()->updateOrCreate(
      ['slug' => 'learner'],
      [
        'name' => 'Learner',
        'guard_name' => AuthGuardName::Member->value,
        'description' => 'Public learner portal access for courses.',
        'is_system' => true,
      ],
    );

    $role->permissions()->syncWithoutDetaching([$permission->id]);

    $memberPortal = Permission::query()->where('slug', 'member.portal')->first();
    $memberRole = Role::query()->where('slug', 'member')->first();
    if ($memberPortal && $memberRole) {
      $memberRole->permissions()->syncWithoutDetaching([$memberPortal->id]);
    }
  }

  public function down(): void
  {
    // Non-destructive.
  }
};
