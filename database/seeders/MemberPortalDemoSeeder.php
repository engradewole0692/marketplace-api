<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev/demo approved member for Member Portal UI verification and Playwright screenshots.
 */
final class MemberPortalDemoSeeder extends Seeder
{
  public const DEMO_EMAIL = 'member.demo@marketplaceministers.org';

  public const DEMO_PASSWORD = 'password';

  public function run(): void
  {
    $memberRole = Role::query()->where('slug', 'member')->firstOrFail();
    $memberPortal = Permission::query()->where('slug', 'member.portal')->firstOrFail();
    $learnerPortal = Permission::query()->where('slug', 'learner.portal')->firstOrFail();

    $user = User::query()->updateOrCreate(
      ['email' => self::DEMO_EMAIL],
      [
        'first_name' => 'Demo',
        'last_name' => 'Member',
        'display_name' => 'Demo Member',
        'password' => Hash::make(self::DEMO_PASSWORD),
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'locale' => 'en',
      ],
    );

    $user->roles()->sync([$memberRole->id]);
    $user->permissions()->sync([$memberPortal->id, $learnerPortal->id]);

    Member::query()->updateOrCreate(
      ['email' => self::DEMO_EMAIL],
      [
        'user_id' => $user->id,
        'membership_number' => 'MM-DEMO-0001',
        'first_name' => 'Demo',
        'last_name' => 'Member',
        'display_name' => 'Demo Member',
        'gender' => 'other',
        'date_of_birth' => '1990-01-15',
        'phone' => '+2348000000001',
        'occupation' => 'Marketplace Professional',
        'organization' => 'Marketplace Ministers',
        'marketplace_sector' => 'technology',
        'skills' => ['Leadership', 'Prayer'],
        'languages' => ['English'],
        'biography' => 'Approved demo member for portal verification.',
        'status' => MemberStatus::Active->value,
        'approval_status' => MemberApprovalStatus::Approved->value,
        'joined_at' => now()->toDateString(),
      ],
    );
  }
}
