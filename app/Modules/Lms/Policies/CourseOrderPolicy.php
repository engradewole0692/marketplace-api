<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\CourseOrder;

final class CourseOrderPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'courses.enroll', 'donations.manage']);
  }

  public function view(User $user, CourseOrder $order): bool
  {
    if ($order->user_id === $user->id) {
      return true;
    }

    return $this->viewAny($user);
  }

  public function checkout(User $user): bool
  {
    // Ownership is enforced in the learner commerce controller.
    return $user->exists;
  }

  public function confirm(User $user): bool
  {
    return $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'donations.confirm']);
  }

  public function refund(User $user): bool
  {
    return $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'donations.manage']);
  }
}
