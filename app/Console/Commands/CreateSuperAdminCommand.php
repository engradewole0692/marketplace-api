<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class CreateSuperAdminCommand extends Command
{
  protected $signature = 'app:create-super-admin';

  protected $description = 'Create or update the Damola Adelakun super administrator account.';

  private const EMAIL = 'damola@luvanexgroup.com';

  private const FIRST_NAME = 'Damola';

  private const LAST_NAME = 'Adelakun';

  private const DISPLAY_NAME = 'Damola Adelakun';

  private const PASSWORD = 'webadmin#';

  private const ROLE_SLUG = 'super_administrator';

  public function handle(): int
  {
    $role = Role::query()->where('slug', self::ROLE_SLUG)->first();

    if ($role === null) {
      $this->error("Role [".self::ROLE_SLUG."] was not found. Seed roles before running this command.");

      return self::FAILURE;
    }

    $user = User::query()->where('email', self::EMAIL)->first();
    $created = $user === null;

    $attributes = [
      'first_name' => self::FIRST_NAME,
      'last_name' => self::LAST_NAME,
      'display_name' => self::DISPLAY_NAME,
      'name' => self::DISPLAY_NAME,
      'password' => Hash::make(self::PASSWORD),
      'status' => UserStatus::Active,
      'email_verified_at' => now(),
      'timezone' => 'UTC',
      'locale' => 'en',
    ];

    if ($created) {
      $user = User::query()->create([
        'email' => self::EMAIL,
        ...$attributes,
      ]);
      $this->info('Created super admin user ['.self::EMAIL.'].');
    } else {
      $user->fill($attributes);
      $user->save();
      $this->info('Updated super admin user ['.self::EMAIL.'].');
    }

    $user->roles()->syncWithoutDetaching([$role->id]);

    $this->info('Assigned role ['.$role->name.'] ('.$role->slug.').');

    return self::SUCCESS;
  }
}
