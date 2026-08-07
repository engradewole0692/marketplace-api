<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthGuardName;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
  public function run(): void
  {
    $roles = [
      [
        'name' => 'Super Administrator',
        'slug' => 'super_administrator',
        'guard_name' => AuthGuardName::SuperAdministrator->value,
        'description' => 'Full platform access across all modules.',
        'is_system' => true,
      ],
      [
        'name' => 'Administrator',
        'slug' => 'administrator',
        'guard_name' => AuthGuardName::Administrator->value,
        'description' => 'Enterprise administration with IAM management.',
        'is_system' => true,
      ],
      [
        'name' => 'Country Administrator',
        'slug' => 'country_administrator',
        'guard_name' => AuthGuardName::Administrator->value,
        'description' => 'Country-level administration and reporting.',
        'is_system' => true,
      ],
      [
        'name' => 'Regional Administrator',
        'slug' => 'regional_administrator',
        'guard_name' => AuthGuardName::Administrator->value,
        'description' => 'Regional leadership and member oversight.',
        'is_system' => true,
      ],
      [
        'name' => 'Ministry Administrator',
        'slug' => 'ministry_administrator',
        'guard_name' => AuthGuardName::Administrator->value,
        'description' => 'Ministry unit administration.',
        'is_system' => true,
      ],
      [
        'name' => 'Leader',
        'slug' => 'leader',
        'guard_name' => AuthGuardName::Leader->value,
        'description' => 'Leadership portal access.',
        'is_system' => true,
      ],
      [
        'name' => 'Instructor',
        'slug' => 'instructor',
        'guard_name' => AuthGuardName::Instructor->value,
        'description' => 'Learning management instructor access.',
        'is_system' => true,
      ],
      [
        'name' => 'Learner',
        'slug' => 'learner',
        'guard_name' => AuthGuardName::Member->value,
        'description' => 'Public learner portal access for courses.',
        'is_system' => true,
      ],
      [
        'name' => 'Member',
        'slug' => 'member',
        'guard_name' => AuthGuardName::Member->value,
        'description' => 'General member access.',
        'is_system' => true,
      ],
    ];

    foreach ($roles as $role) {
      Role::query()->updateOrCreate(
        ['slug' => $role['slug']],
        $role,
      );
    }
  }
}
