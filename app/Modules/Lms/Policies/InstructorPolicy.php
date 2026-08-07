<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\Instructor;

final class InstructorPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish', 'courses.teach']);
  }

  public function view(User $user, Instructor $instructor): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function update(User $user, Instructor $instructor): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish', 'courses.teach']);
  }

  public function delete(User $user, Instructor $instructor): bool
  {
    return $user->hasPermission('courses.manage');
  }
}
