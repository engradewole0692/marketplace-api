<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Support\ChecksEventScope;

final class EventRegistrationPaymentPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage', 'registrations.view']);
  }

  public function view(User $user, EventRegistrationPayment $payment): bool
  {
    if (! $this->viewAny($user)) {
      return false;
    }

    $payment->loadMissing('event');

    return $this->eventIsAccessible($user, $payment->event);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function update(User $user, EventRegistrationPayment $payment): bool
  {
    if (! $this->create($user)) {
      return false;
    }

    $payment->loadMissing('event');

    return $this->eventIsAccessible($user, $payment->event);
  }
}
