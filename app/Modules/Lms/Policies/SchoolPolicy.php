<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\LmsSchool;

final class SchoolPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish', 'courses.teach', 'courses.review', 'courses.enroll']);
  }

  public function view(User $user, LmsSchool $school): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function update(User $user, LmsSchool $school): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function delete(User $user, LmsSchool $school): bool
  {
    return $user->hasPermission('courses.manage');
  }

  public function publish(User $user, LmsSchool $school): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }
}
