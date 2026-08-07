<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Iam\PermissionCatalog;
use Illuminate\Database\Seeder;

final class PermissionSeeder extends Seeder
{
  public function run(): void
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
  }
}
