<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Support\CmsPermission;

final class CmsSeoPolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.seo.manage');
  }

  public function view(User $user, CmsSeo $seo): bool
  {
    return CmsPermission::allows($user, 'cms.seo.manage');
  }

  public function create(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.seo.manage');
  }

  public function update(User $user, CmsSeo $seo): bool
  {
    return CmsPermission::allows($user, 'cms.seo.manage');
  }

  public function delete(User $user, CmsSeo $seo): bool
  {
    return CmsPermission::allows($user, 'cms.seo.manage');
  }
}
