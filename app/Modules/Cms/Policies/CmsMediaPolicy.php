<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Support\CmsPermission;

final class CmsMediaPolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.media.manage') || $user->hasPermission('media.manage');
  }

  public function view(User $user, CmsMedia $media): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $this->viewAny($user);
  }

  public function update(User $user, CmsMedia $media): bool
  {
    return $this->viewAny($user);
  }

  public function delete(User $user, CmsMedia $media): bool
  {
    return $this->viewAny($user);
  }
}
