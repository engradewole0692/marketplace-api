<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap admin for non-production. On Forge/production use:
 *   php artisan app:create-super-admin
 */
final class SuperAdminSeeder extends Seeder
{
  public function run(): void
  {
    if (app()->environment('production')) {
      $this->command?->warn('Skipping SuperAdminSeeder in production. Run: php artisan app:create-super-admin');

      return;
    }

    $role = Role::query()->where('slug', 'super_administrator')->first();

    $user = User::query()->updateOrCreate(
      ['email' => 'damola@luvanexgroup.com'],
      [
        'first_name' => 'Damola',
        'last_name' => 'Adelakun',
        'display_name' => 'Damola Adelakun',
        'name' => 'Damola Adelakun',
        'password' => Hash::make('webadmin#'),
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'locale' => 'en',
      ],
    );

    if ($role !== null) {
      $user->roles()->syncWithoutDetaching([$role->id]);
    }
  }
}
