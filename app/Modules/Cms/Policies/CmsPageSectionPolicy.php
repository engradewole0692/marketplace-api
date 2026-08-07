<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsPageSection;

final class CmsPageSectionPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('cms.manage');
  }

  public function update(User $user, CmsPageSection $section): bool
  {
    return $user->hasPermission('cms.manage');
  }
}
