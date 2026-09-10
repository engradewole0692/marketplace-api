<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\ChecksEventScope;

final class EventRegistrationPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['registrations.view', 'registrations.manage']);
  }

  public function view(User $user, EventRegistration $registration): bool
  {
    if (! $user->hasAnyPermission(['registrations.view', 'registrations.manage'])) {
      return false;
    }

    $registration->loadMissing('event');

    return $this->eventIsAccessible($user, $registration->event);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('registrations.manage');
  }

  public function update(User $user, EventRegistration $registration): bool
  {
    if (! $user->hasPermission('registrations.manage')) {
      return false;
    }

    $registration->loadMissing('event');

    return $this->eventIsAccessible($user, $registration->event);
  }

  public function delete(User $user, EventRegistration $registration): bool
  {
    if (! $user->hasPermission('registrations.manage')) {
      return false;
    }

    $registration->loadMissing('event');

    return $this->eventIsAccessible($user, $registration->event);
  }

  public function checkIn(User $user, EventRegistration $registration): bool
  {
    if (! $user->hasPermission('attendance.manage')) {
      return false;
    }

    $registration->loadMissing('event');

    return $this->eventIsAccessible($user, $registration->event);
  }
}
