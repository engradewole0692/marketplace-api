<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

final class PermissionPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('permissions.view');
  }

  public function view(User $user, Permission $permission): bool
  {
    return $user->hasPermission('permissions.view');
  }
}
