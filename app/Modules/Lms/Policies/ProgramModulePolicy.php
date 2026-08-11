<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\LmsProgramModule;

final class ProgramModulePolicy
{
  public function view(User $user, LmsProgramModule $module): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish', 'admin.access']);
  }

  public function update(User $user, LmsProgramModule $module): bool
  {
    return $user->hasAnyPermission(['courses.manage', 'courses.publish']);
  }

  public function delete(User $user, LmsProgramModule $module): bool
  {
    return $user->hasPermission('courses.manage');
  }
}
