<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Support\CmsPermission;

final class CmsMenuPolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.menus.manage');
  }

  public function view(User $user, CmsMenu $menu): bool
  {
    return $this->viewAny($user);
  }

  public function update(User $user, CmsMenu $menu): bool
  {
    return $this->viewAny($user);
  }
}
