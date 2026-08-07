<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Support\Iam\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * LMS permissions were added to PermissionCatalog after some environments
 * were seeded. Without this sync, nav items like Commerce/Settings disappear
 * because courses.manage / course_payments.manage never exist in the DB.
 */
return new class extends Migration
{
  public function up(): void
  {
    foreach (PermissionCatalog::all() as $permission) {
      Permission::query()->updateOrCreate(
        ['slug' => $permission['slug']],
        [
          'name' => $permission['name'],
          'module' => $permission['module'],
          'group' => $permission['group'],
          'description' => $permission['description'],
          'is_system' => true,
        ],
      );
    }

    $superAdmin = Role::query()->where('slug', 'super_administrator')->first();
    if ($superAdmin !== null) {
      $superAdmin->permissions()->sync(Permission::query()->pluck('id')->all());
    }
  }

  public function down(): void
  {
    // Intentionally non-destructive — do not remove permissions.
  }
};
