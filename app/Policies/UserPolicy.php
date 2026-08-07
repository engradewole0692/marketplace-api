<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

final class UserPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('users.view');
  }

  public function view(User $user, User $model): bool
  {
    return $user->hasPermission('users.view');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('users.create');
  }

  public function update(User $user, User $model): bool
  {
    return $user->hasPermission('users.update');
  }

  public function delete(User $user, User $model): bool
  {
    return $user->hasPermission('users.delete');
  }

  public function restore(User $user, User $model): bool
  {
    return $user->hasPermission('users.restore');
  }

  public function bulk(User $user): bool
  {
    return $user->hasPermission('users.bulk');
  }
}
