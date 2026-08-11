<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Http\Resources\EnrollmentResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EnrollmentAdminController extends ApiController
{
  public function store(Request $request, EnrollmentService $service): JsonResponse
  {
    $this->authorize('create', Enrollment::class);

    $validated = $request->validate([
      'course_id' => ['required', 'uuid'],
      'user_id' => ['required', 'uuid'],
      'learner_type' => ['nullable', 'string', Rule::in(array_column(LearnerType::cases(), 'value'))],
      'coupon_code' => ['nullable', 'string', 'max:60'],
    ]);

    $course = Course::query()->where('uuid', $validated['course_id'])->firstOrFail();
    $user = User::query()->where('uuid', $validated['user_id'])->firstOrFail();
    $learnerType = LearnerType::tryFrom($validated['learner_type'] ?? 'public') ?? LearnerType::Public;

    $enrollment = $service->enroll($course, $user, $learnerType, $validated['coupon_code'] ?? null);

    return $this->responder->success(
      data: ['enrollment' => new EnrollmentResource($enrollment)],
      message: 'Enrollment created.',
      status: 201,
    );
  }

  public function cancel(Enrollment $enrollment, Request $request, EnrollmentService $service): JsonResponse
  {
    $this->authorize('update', $enrollment);

    return $this->responder->success(
      data: ['enrollment' => new EnrollmentResource($service->cancel($enrollment, $request->user()))],
      message: 'Enrollment cancelled.',
    );
  }

  public function lock(Request $request, Enrollment $enrollment, EnrollmentService $service): JsonResponse
  {
    $this->authorize('update', $enrollment);

    $validated = $request->validate([
      'reason' => ['nullable', 'string', 'max:500'],
    ]);

    return $this->responder->success(
      data: ['enrollment' => new EnrollmentResource($service->lock($enrollment, $request->user(), $validated['reason'] ?? null))],
      message: 'Enrollment locked.',
    );
  }

  public function restart(Enrollment $enrollment, Request $request, EnrollmentService $service): JsonResponse
  {
    $this->authorize('update', $enrollment);

    return $this->responder->success(
      data: ['enrollment' => new EnrollmentResource($service->restart($enrollment, $request->user()))],
      message: 'Enrollment restarted.',
    );
  }
}
