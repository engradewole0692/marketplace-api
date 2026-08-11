<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;

/**
 * Central LMS authorization helpers — super-admin testing access, etc.
 */
final class LmsAccessService implements ServiceContract
{
  /** @var list<string> */
  private const SUPER_ADMIN_ROLE_SLUGS = [
    'super_administrator',
    'super-admin',
    'super_admin',
  ];

  public function isSuperAdministrator(User $user): bool
  {
    foreach (self::SUPER_ADMIN_ROLE_SLUGS as $slug) {
      if ($user->hasRole($slug)) {
        return true;
      }
    }

    return false;
  }

  /** Super admins may access all LMS content without payment. */
  public function bypassesPaidLmsAccess(User $user): bool
  {
    return $this->isSuperAdministrator($user);
  }
}
