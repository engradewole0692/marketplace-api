<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventVolunteerRole;

final class EventVolunteerRolePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function view(User $user, EventVolunteerRole $role): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function update(User $user, EventVolunteerRole $role): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, EventVolunteerRole $role): bool
  {
    return $this->create($user);
  }
}
