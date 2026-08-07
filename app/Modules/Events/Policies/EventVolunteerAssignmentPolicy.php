<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventVolunteerAssignment;

final class EventVolunteerAssignmentPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function view(User $user, EventVolunteerAssignment $assignment): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['volunteers.manage', 'events.manage']);
  }

  public function update(User $user, EventVolunteerAssignment $assignment): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, EventVolunteerAssignment $assignment): bool
  {
    return $this->create($user);
  }
}
