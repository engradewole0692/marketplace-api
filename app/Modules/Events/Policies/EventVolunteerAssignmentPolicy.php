<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Support\ChecksEventScope;

final class EventVolunteerAssignmentPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function view(User $user, EventVolunteerAssignment $assignment): bool
  {
    if (! $this->viewAny($user)) {
      return false;
    }

    $assignment->loadMissing('event');

    return $this->eventIsAccessible($user, $assignment->event);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function update(User $user, EventVolunteerAssignment $assignment): bool
  {
    if (! $this->create($user)) {
      return false;
    }

    $assignment->loadMissing('event');

    return $this->eventIsAccessible($user, $assignment->event);
  }

  public function delete(User $user, EventVolunteerAssignment $assignment): bool
  {
    return $this->update($user, $assignment);
  }
}
