<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsMinistry;

final class CmsMinistryPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('ministries.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('ministries.manage');
  }

  public function update(User $user, CmsMinistry $ministry): bool
  {
    return $user->hasPermission('ministries.manage');
  }

  public function delete(User $user, CmsMinistry $ministry): bool
  {
    return $user->hasPermission('ministries.manage');
  }
}
