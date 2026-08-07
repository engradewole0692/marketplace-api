<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev/demo visitor (learner-only) for Visitor Workspace verification.
 * Must NOT receive member.portal.
 */
final class VisitorWorkspaceDemoSeeder extends Seeder
{
  public const DEMO_EMAIL = 'learner.demo@marketplaceministers.org';

  public const DEMO_PASSWORD = 'password';

  public function run(): void
  {
    $learnerRole = Role::query()->where('slug', 'learner')->firstOrFail();
    $learnerPortal = Permission::query()->where('slug', 'learner.portal')->firstOrFail();

    $user = User::query()->updateOrCreate(
      ['email' => self::DEMO_EMAIL],
      [
        'first_name' => 'Demo',
        'last_name' => 'Visitor',
        'display_name' => 'Demo Visitor',
        'password' => Hash::make(self::DEMO_PASSWORD),
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'locale' => 'en',
      ],
    );

    $user->roles()->sync([$learnerRole->id]);
    $user->permissions()->sync([$learnerPortal->id]);
  }
}
