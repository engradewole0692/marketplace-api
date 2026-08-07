<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\CourseCategory;

final class CourseCategoryPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish', 'courses.teach']);
  }

  public function view(User $user, CourseCategory $category): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function update(User $user, CourseCategory $category): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function delete(User $user, CourseCategory $category): bool
  {
    return $user->hasPermission('courses.manage');
  }
}
