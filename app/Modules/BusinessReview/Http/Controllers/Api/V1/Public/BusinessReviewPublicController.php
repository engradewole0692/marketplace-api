<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\BusinessReview\Services\BusinessReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BusinessReviewPublicController extends ApiController
{
    public function store(Request $request, BusinessReviewService $service): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_location' => ['nullable', 'string', 'max:255'],
            'business_industry' => ['nullable', 'string', 'max:255'],
            'business_description' => ['nullable', 'string', 'max:2000'],
            'business_stage' => ['nullable', 'string', 'max:100'],
            'main_challenges' => ['nullable', 'string', 'max:2000'],
            'business_goals' => ['nullable', 'string', 'max:2000'],
            'website_social' => ['nullable', 'string', 'max:500'],
            'preferred_contact' => ['nullable', 'string', 'in:email,phone,whatsapp'],
            'additional_info' => ['nullable', 'string', 'max:2000'],
            'extra_answers' => ['nullable', 'array'],
        ]);

        $review = $service->submit($validated, $request->ip());

        return $this->responder->success(
            data: ['id' => $review->uuid, 'email' => $review->email],
            message: 'Your application has been submitted. We will be in touch soon.',
            status: 201,
        );
    }
}
