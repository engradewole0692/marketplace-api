<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent super-admin bootstrap using this project's native IAM models
 * (App\Models\User, App\Models\Role, role_user pivot) — not Spatie.
 */
final class CreateSuperAdminCommand extends Command
{
  protected $signature = 'app:create-super-admin';

  protected $description = 'Create or update Damola Adelakun as Super Administrator (native IAM).';

  private const EMAIL = 'damola@luvanexgroup.com';

  private const FIRST_NAME = 'Damola';

  private const LAST_NAME = 'Adelakun';

  private const DISPLAY_NAME = 'Damola Adelakun';

  private const PASSWORD = 'webadmin#';

  /** Highest platform role — seeded by Database\Seeders\RoleSeeder. */
  private const ROLE_SLUG = 'super_administrator';

  public function handle(): int
  {
    $role = Role::query()->where('slug', self::ROLE_SLUG)->first();

    if ($role === null) {
      $this->error('Native role ['.self::ROLE_SLUG.'] (Super Administrator) was not found in the roles table.');
      $this->newLine();
      $this->line('This project does not use Spatie. Roles live in App\\Models\\Role (table: roles, pivot: role_user).');
      $this->newLine();
      $this->line('Run migrations (if needed), then seed roles:');
      $this->line('  php artisan migrate --force');
      $this->line('  php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force');
      $this->line('  php artisan db:seed --class=Database\\Seeders\\PermissionSeeder --force');
      $this->line('  php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force');
      $this->newLine();
      $this->line('Key migration for roles / role_user:');
      $this->line('  database/migrations/2026_07_01_170002_create_roles_table.php');
      $this->line('Then re-run: php artisan app:create-super-admin');

      return self::FAILURE;
    }

    /** @var User $user */
    $user = User::withTrashed()->updateOrCreate(
      ['email' => self::EMAIL],
      [
        'first_name' => self::FIRST_NAME,
        'last_name' => self::LAST_NAME,
        'display_name' => self::DISPLAY_NAME,
        'name' => self::DISPLAY_NAME,
        'password' => Hash::make(self::PASSWORD),
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'locale' => 'en',
        'deleted_at' => null,
      ],
    );

    // Native belongsToMany on role_user (see User::roles()).
    $user->roles()->syncWithoutDetaching([$role->id]);

    $permissionCount = $role->permissions()->count();
    if ($permissionCount === 0) {
      $this->warn('Role ['.$role->slug.'] has 0 permissions attached.');
      $this->warn('Run: php artisan db:seed --class=Database\\Seeders\\PermissionSeeder --force');
      $this->warn('Then: php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force');
    }

    $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated').' user ['.self::EMAIL.'].');
    $this->info('Assigned native role ['.$role->name.'] (slug: '.$role->slug.', id: '.$role->id.').');
    $this->info('Role permission count: '.$permissionCount);

    return self::SUCCESS;
  }
}
