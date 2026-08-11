<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\AssessmentAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearnerAssessmentController extends ApiController
{
  public function forLesson(Request $request, string $lessonId): JsonResponse
  {
    $assessment = Assessment::query()
      ->whereHas('lesson', fn ($q) => $q->where('uuid', $lessonId))
      ->where('status', 'published')
      ->first();

    if (! $assessment) {
      return $this->responder->success(data: ['assessment' => null], message: 'No assessment linked.');
    }

    return $this->responder->success(
      data: [
        'assessment' => [
          'id' => $assessment->uuid,
          'title' => $assessment->title,
          'assessment_type' => $assessment->assessment_type instanceof \BackedEnum
            ? $assessment->assessment_type->value
            : $assessment->assessment_type,
          'pass_mark' => (float) $assessment->pass_mark,
          'time_limit_seconds' => $assessment->time_limit_seconds,
          'max_attempts' => $assessment->max_attempts,
        ],
      ],
      message: 'Assessment found.',
    );
  }

  public function start(Request $request, Assessment $assessment, AssessmentAttemptService $service): JsonResponse
  {
    $validated = $request->validate([
      'enrollment_id' => ['nullable', 'uuid'],
    ]);
    $enrollment = null;
    if (! empty($validated['enrollment_id'])) {
      $enrollment = Enrollment::query()
        ->where('uuid', $validated['enrollment_id'])
        ->where('user_id', $request->user()->id)
        ->firstOrFail();
    }

    $attempt = $service->start($assessment, $request->user(), $enrollment);

    return $this->responder->success(
      data: $service->takePayload($attempt),
      message: 'Attempt started.',
      status: 201,
    );
  }

  public function submit(Request $request, AssessmentAttempt $attempt, AssessmentAttemptService $service): JsonResponse
  {
    $validated = $request->validate([
      'answers' => ['required', 'array'],
      'answers.*.question_id' => ['required', 'uuid'],
      'answers.*.response' => ['nullable', 'array'],
    ]);

    $attempt = $service->submit($attempt, $validated['answers'], $request->user());

    return $this->responder->success(
      data: ['result' => $service->resultPayload($attempt)],
      message: 'Attempt submitted.',
    );
  }

  public function result(Request $request, AssessmentAttempt $attempt, AssessmentAttemptService $service): JsonResponse
  {
    abort_unless($attempt->user_id === $request->user()->id, 403);

    return $this->responder->success(
      data: ['result' => $service->resultPayload($attempt)],
      message: 'Result retrieved.',
    );
  }

  public function transcript(Request $request, \App\Modules\Lms\Services\TranscriptService $service): JsonResponse
  {
    return $this->responder->success(
      data: $service->forUser($request->user(), notifyIfAvailable: true),
      message: 'Transcript retrieved.',
    );
  }
}
