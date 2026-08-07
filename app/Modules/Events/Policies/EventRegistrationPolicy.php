<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventRegistration;

final class EventRegistrationPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['registrations.view', 'registrations.manage']);
  }

  public function view(User $user, EventRegistration $registration): bool
  {
    return $user->hasAnyPermission(['registrations.view', 'registrations.manage']);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('registrations.manage');
  }

  public function update(User $user, EventRegistration $registration): bool
  {
    return $user->hasPermission('registrations.manage');
  }

  public function delete(User $user, EventRegistration $registration): bool
  {
    return $user->hasPermission('registrations.manage');
  }

  public function checkIn(User $user, EventRegistration $registration): bool
  {
    return $user->hasPermission('attendance.manage');
  }
}
