<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\SchoolOrder;

final class SchoolOrderPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'courses.enroll', 'donations.manage']);
  }

  public function view(User $user, SchoolOrder $order): bool
  {
    if ($order->user_id === $user->id) {
      return true;
    }

    return $this->viewAny($user);
  }

  public function confirm(User $user): bool
  {
    return $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'donations.confirm']);
  }

  public function reject(User $user): bool
  {
    return $this->confirm($user);
  }
}
