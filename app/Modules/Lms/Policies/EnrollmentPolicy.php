<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\Enrollment;

final class EnrollmentPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.enroll']);
  }

  public function view(User $user, Enrollment $enrollment): bool
  {
    if ($enrollment->user_id === $user->id) {
      return true;
    }

    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.enroll', 'learner.portal', 'member.portal', 'admin.access']);
  }

  public function update(User $user, Enrollment $enrollment): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.enroll']);
  }

  public function delete(User $user, Enrollment $enrollment): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.enroll']);
  }
}
