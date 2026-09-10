<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventVolunteerRole;
use App\Modules\Events\Support\ChecksEventScope;

final class EventVolunteerRolePolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function view(User $user, EventVolunteerRole $role): bool
  {
    if (! $this->viewAny($user)) {
      return false;
    }

    $role->loadMissing('event');

    return $this->eventIsAccessible($user, $role->event);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function update(User $user, EventVolunteerRole $role): bool
  {
    if (! $this->create($user)) {
      return false;
    }

    $role->loadMissing('event');

    return $this->eventIsAccessible($user, $role->event);
  }

  public function delete(User $user, EventVolunteerRole $role): bool
  {
    return $this->update($user, $role);
  }
}
