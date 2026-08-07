<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MemberInterview;
use App\Models\User;

final class MemberInterviewPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['interviews.manage', 'members.view', 'members.manage']);
  }

  public function view(User $user, MemberInterview $interview): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['interviews.manage', 'members.manage', 'members.approve']);
  }

  public function update(User $user, MemberInterview $interview): bool
  {
    return $this->create($user);
  }
}
