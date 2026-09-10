<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventCoupon;
use App\Modules\Events\Support\ChecksEventScope;

final class EventCouponPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function view(User $user, EventCoupon $coupon): bool
  {
    if (! $this->viewAny($user)) {
      return false;
    }

    $coupon->loadMissing('event');

    return $this->eventIsAccessible($user, $coupon->event);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function update(User $user, EventCoupon $coupon): bool
  {
    if (! $this->create($user)) {
      return false;
    }

    $coupon->loadMissing('event');

    return $this->eventIsAccessible($user, $coupon->event);
  }

  public function delete(User $user, EventCoupon $coupon): bool
  {
    return $this->update($user, $coupon);
  }
}
