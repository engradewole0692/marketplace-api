<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Question;

final class AssessmentPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['assessments.manage', 'courses.teach', 'courses.manage']);
  }

  public function view(User $user, Assessment|Question $model): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['assessments.manage', 'courses.manage']);
  }

  public function update(User $user, Assessment|Question $model): bool
  {
    return $user->hasAnyPermission(['assessments.manage', 'courses.manage', 'courses.teach']);
  }

  public function delete(User $user, Assessment|Question $model): bool
  {
    return $user->hasAnyPermission(['assessments.manage', 'courses.manage']);
  }

  public function grade(User $user): bool
  {
    return $user->hasAnyPermission(['assessments.manage', 'courses.teach', 'courses.manage']);
  }
}
