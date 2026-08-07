<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Support\CmsPermission;

final class CmsPagePolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.pages.view');
  }

  public function view(User $user, CmsPage $page): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.pages.manage');
  }

  public function update(User $user, CmsPage $page): bool
  {
    return CmsPermission::allows($user, 'cms.pages.manage');
  }

  public function delete(User $user, CmsPage $page): bool
  {
    return CmsPermission::allows($user, 'cms.pages.manage');
  }

  public function publish(User $user, CmsPage $page): bool
  {
    return CmsPermission::allows($user, 'cms.pages.publish');
  }
}
