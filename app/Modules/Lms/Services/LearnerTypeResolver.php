<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Lms\Enums\LearnerType;

/**
 * Resolves LMS learner type from authoritative membership state — not IAM roles alone.
 */
final class LearnerTypeResolver implements ServiceContract
{
  public function resolve(User $user): LearnerType
  {
    $member = Member::query()->where('user_id', $user->id)->first();

    if ($member !== null && $member->qualifiesForMemberPricing()) {
      return LearnerType::Member;
    }

    return LearnerType::Public;
  }

  public function resolveMember(User $user): ?Member
  {
    return Member::query()->where('user_id', $user->id)->first();
  }
}
