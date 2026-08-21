<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\BusinessReview\Http\Requests\SubmitBusinessReviewRequest;
use App\Modules\BusinessReview\Services\BusinessReviewService;
use Illuminate\Http\JsonResponse;

final class BusinessReviewPublicController extends ApiController
{
    public function store(SubmitBusinessReviewRequest $request, BusinessReviewService $service): JsonResponse
    {
        $review = $service->submit(
            $request->validated(),
            $request->ip(),
            $request->user(),
        );

        return $this->responder->success(
            data: ['id' => $review->uuid, 'email' => $review->email],
            message: 'Your application has been submitted. We will be in touch soon.',
            status: 201,
        );
    }
}
