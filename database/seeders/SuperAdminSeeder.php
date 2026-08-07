<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class SuperAdminSeeder extends Seeder
{
  public function run(): void
  {
    $role = Role::query()->where('slug', 'super_administrator')->first();

    $user = User::query()->updateOrCreate(
      ['email' => 'admin@marketplaceministers.org'],
      [
        'first_name' => 'Super',
        'last_name' => 'Administrator',
        'display_name' => 'Super Administrator',
        'password' => Hash::make('password'),
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
