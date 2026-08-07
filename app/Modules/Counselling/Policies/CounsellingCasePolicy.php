<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Policies;

use App\Models\User;
use App\Modules\Counselling\Models\CounsellingCase;

final class CounsellingCasePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['counselling.manage', 'counselling.view', 'counsellor.portal']);
  }

  public function view(User $user, CounsellingCase $case): bool
  {
    if ($user->hasAnyPermission(['counselling.manage', 'counselling.view'])) {
      return true;
    }

    if ((int) $case->user_id === (int) $user->id) {
      return true;
    }

    if (strcasecmp((string) $case->client_email, (string) $user->email) === 0) {
      return true;
    }

    $case->loadMissing('counsellor');
    if ($case->counsellor !== null && (int) $case->counsellor->user_id === (int) $user->id) {
      return true;
    }

    return false;
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['learner.portal', 'member.portal', 'counselling.manage']);
  }

  public function update(User $user, CounsellingCase $case): bool
  {
    if ($user->hasPermission('counselling.manage')) {
      return true;
    }

    $case->loadMissing('counsellor');

    return $case->counsellor !== null && (int) $case->counsellor->user_id === (int) $user->id;
  }

  public function delete(User $user, CounsellingCase $case): bool
  {
    return $user->hasPermission('counselling.manage');
  }
}
