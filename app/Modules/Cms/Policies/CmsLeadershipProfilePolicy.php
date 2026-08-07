<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsLeadershipProfile;

final class CmsLeadershipProfilePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('leadership.manage');
  }

  public function view(User $user, CmsLeadershipProfile $profile): bool
  {
    return $user->hasPermission('leadership.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('leadership.manage');
  }

  public function update(User $user, CmsLeadershipProfile $profile): bool
  {
    return $user->hasPermission('leadership.manage');
  }

  public function delete(User $user, CmsLeadershipProfile $profile): bool
  {
    return $user->hasPermission('leadership.manage');
  }
}
