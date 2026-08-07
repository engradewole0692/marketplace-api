<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventCoupon;

final class EventCouponPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function view(User $user, EventCoupon $coupon): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['event_payments.manage', 'events.manage']);
  }

  public function update(User $user, EventCoupon $coupon): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, EventCoupon $coupon): bool
  {
    return $this->create($user);
  }
}
