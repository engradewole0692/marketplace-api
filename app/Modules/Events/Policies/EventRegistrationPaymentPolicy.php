<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventRegistrationPayment;

final class EventRegistrationPaymentPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage', 'registrations.view']);
  }

  public function view(User $user, EventRegistrationPayment $payment): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function update(User $user, EventRegistrationPayment $payment): bool
  {
    return $this->create($user);
  }
}
