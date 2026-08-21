<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Support;

final class BusinessReviewConfig
{
    /** @var list<string> */
    public const STATUSES = [
        'new',
        'under_review',
        'information_requested',
        'review_completed',
        'closed',
    ];

    /** @var list<string> */
    public const STAGES = [
        'Business Idea',
        'Startup',
        'Existing Business',
        'Growing Business',
        'Expanding Business',
    ];
}
