<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\SchoolEnrollmentResource;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\SchoolEnrollmentService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SchoolEnrollmentAdminController extends ApiController
{
  public function index(Request $request, SchoolEnrollmentService $service): JsonResponse
  {
    $this->authorize('viewAny', LmsSchool::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginate($request->query()),
        SchoolEnrollmentResource::class,
      ),
      message: 'School enrollments retrieved.',
    );
  }

  public function store(Request $request, SchoolEnrollmentService $service): JsonResponse
  {
    $this->authorize('create', LmsSchool::class);
    $validated = $request->validate([
      'school_id' => ['required', 'uuid'],
      'user_id' => ['required', 'uuid'],
      'learner_type' => ['nullable', 'string', Rule::in(['member', 'public'])],
    ]);

    $school = LmsSchool::query()->where('uuid', $validated['school_id'])->firstOrFail();
    $user = \App\Models\User::query()->where('uuid', $validated['user_id'])->firstOrFail();
    $learnerType = \App\Modules\Lms\Enums\LearnerType::tryFrom($validated['learner_type'] ?? 'public')
      ?? \App\Modules\Lms\Enums\LearnerType::Public;

    $enrollment = $service->enroll($school, $user, $learnerType);

    return $this->responder->success(
      data: ['enrollment' => new SchoolEnrollmentResource($enrollment)],
      message: 'School enrollment created.',
      status: 201,
    );
  }

  public function cancel(SchoolEnrollment $enrollment, SchoolEnrollmentService $service): JsonResponse
  {
    $this->authorize('update', $enrollment->school);

    return $this->responder->success(
      data: ['enrollment' => new SchoolEnrollmentResource($service->cancel($enrollment))],
      message: 'School enrollment cancelled.',
    );
  }

  public function activate(SchoolEnrollment $enrollment, SchoolEnrollmentService $service): JsonResponse
  {
    $this->authorize('update', $enrollment->school);

    return $this->responder->success(
      data: ['enrollment' => new SchoolEnrollmentResource($service->activate($enrollment))],
      message: 'School enrollment activated.',
    );
  }
}
