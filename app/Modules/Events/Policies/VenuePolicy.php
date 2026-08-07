<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Venue;

final class VenuePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['events.view', 'venues.manage']);
  }

  public function view(User $user, Venue $venue): bool
  {
    return $user->hasAnyPermission(['events.view', 'venues.manage']);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('venues.manage');
  }

  public function update(User $user, Venue $venue): bool
  {
    return $user->hasPermission('venues.manage');
  }

  public function delete(User $user, Venue $venue): bool
  {
    return $user->hasPermission('venues.manage');
  }
}
