<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsCatalogItem;

final class CmsCatalogItemPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['cms.manage', 'blog.manage', 'gallery.manage', 'resources.manage']);
  }

  public function create(User $user): bool
  {
    return $this->viewAny($user);
  }

  public function update(User $user, CmsCatalogItem $item): bool
  {
    return $this->viewAny($user);
  }

  public function delete(User $user, CmsCatalogItem $item): bool
  {
    return $this->viewAny($user);
  }
}
