<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

final class RolePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('roles.view');
  }

  public function view(User $user, Role $role): bool
  {
    return $user->hasPermission('roles.view');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('roles.create');
  }

  public function update(User $user, Role $role): bool
  {
    return $user->hasPermission('roles.update');
  }

  public function delete(User $user, Role $role): bool
  {
    return $user->hasPermission('roles.delete');
  }

  public function clone(User $user, Role $role): bool
  {
    return $user->hasPermission('roles.clone');
  }
}
