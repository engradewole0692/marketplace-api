<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Models\User;

final class CmsPermission
{
  public static function allows(User $user, string $permission): bool
  {
    return $user->hasPermission('cms.manage') || $user->hasPermission($permission);
  }
}
