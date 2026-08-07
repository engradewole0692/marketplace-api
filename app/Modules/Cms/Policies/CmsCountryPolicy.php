<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;

final class CmsCountryPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('countries.manage');
  }

  public function view(User $user, CmsCountry $country): bool
  {
    return $user->hasPermission('countries.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('countries.manage');
  }

  public function update(User $user, CmsCountry $country): bool
  {
    return $user->hasPermission('countries.manage');
  }

  public function delete(User $user, CmsCountry $country): bool
  {
    return $user->hasPermission('countries.manage');
  }
}
