<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Policies;

use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;

final class BusinessReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('business-review.view') || $user->hasPermission('settings.manage');
    }

    public function view(User $user, BusinessReview $review): bool
    {
        return $user->hasPermission('business-review.view') || $user->hasPermission('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('business-review.manage') || $user->hasPermission('settings.manage');
    }

    public function update(User $user, BusinessReview $review): bool
    {
        return $user->hasPermission('business-review.manage') || $user->hasPermission('settings.manage');
    }

    public function assign(User $user, BusinessReview $review): bool
    {
        return $user->hasPermission('business-review.manage') || $user->hasPermission('settings.manage');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('business-review.export')
            || $user->hasPermission('business-review.manage')
            || $user->hasPermission('settings.manage');
    }

    public function delete(User $user, BusinessReview $review): bool
    {
        return $user->hasPermission('settings.manage');
    }
}
