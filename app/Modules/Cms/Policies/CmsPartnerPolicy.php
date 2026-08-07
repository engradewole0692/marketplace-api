<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Support\CmsPermission;

final class CmsPartnerPolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.partners.manage');
  }

  public function view(User $user, CmsPartner $partner): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $this->viewAny($user);
  }

  public function update(User $user, CmsPartner $partner): bool
  {
    return $this->viewAny($user);
  }

  public function delete(User $user, CmsPartner $partner): bool
  {
    return $this->viewAny($user);
  }
}
