<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsSetting;

final class CmsSettingPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('settings.manage');
  }

  public function update(User $user, CmsSetting $setting): bool
  {
    return $user->hasPermission('settings.manage');
  }
}
