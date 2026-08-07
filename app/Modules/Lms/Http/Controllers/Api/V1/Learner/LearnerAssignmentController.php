<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\AssignmentSubmissionResource;
use App\Modules\Lms\Models\Assignment;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearnerAssignmentController extends ApiController
{
  public function index(Request $request, AssignmentService $service): JsonResponse
  {
    return $this->responder->success(
      data: ['assignments' => $service->forLearner($request->user())],
      message: 'Learner assignments loaded.',
    );
  }

  public function submit(Request $request, Assignment $assignment, AssignmentService $service): JsonResponse
  {
    $validated = $request->validate([
      'enrollment_id' => ['required', 'uuid'],
      'essay_body' => ['nullable', 'string'],
      'objective_answers' => ['nullable', 'array'],
      'attachments' => ['nullable', 'array'],
      'attachments.*.media_id' => ['nullable', 'uuid'],
      'attachments.*.url' => ['nullable', 'url'],
      'attachments.*.name' => ['nullable', 'string', 'max:255'],
    ]);

    $enrollment = Enrollment::query()->where('uuid', $validated['enrollment_id'])->first();
    if ($enrollment === null) {
      throw new BusinessException('Enrollment not found.', ApiErrorCode::NotFound, null, 404);
    }

    $submission = $service->submit($assignment, $enrollment, $request->user(), $validated);

    return $this->responder->success(
      data: ['submission' => new AssignmentSubmissionResource($submission)],
      message: 'Assignment submitted.',
      status: 201,
    );
  }
}
