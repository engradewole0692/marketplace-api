<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsMediaFolder;

final class CmsMediaFolderPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('media.manage');
  }

  public function view(User $user, CmsMediaFolder $folder): bool
  {
    return $user->hasPermission('media.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('media.manage');
  }

  public function update(User $user, CmsMediaFolder $folder): bool
  {
    return $user->hasPermission('media.manage');
  }

  public function delete(User $user, CmsMediaFolder $folder): bool
  {
    return $user->hasPermission('media.manage');
  }
}
