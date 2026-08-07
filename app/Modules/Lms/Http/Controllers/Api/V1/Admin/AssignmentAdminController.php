<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\AssignmentResource;
use App\Modules\Lms\Http\Resources\AssignmentSubmissionResource;
use App\Modules\Lms\Models\Assignment;
use App\Modules\Lms\Models\AssignmentSubmission;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\AssignmentService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssignmentAdminController extends ApiController
{
  public function index(Request $request, AssignmentService $service): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginateAdmin($request->query()), AssignmentResource::class),
      message: 'Assignments retrieved.',
    );
  }

  public function store(Request $request, AssignmentService $service): JsonResponse
  {
    $this->authorize('create', Course::class);
    $validated = $request->validate([
      'course_id' => ['required', 'uuid'],
      'lesson_id' => ['nullable', 'uuid'],
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'type' => ['nullable', 'string', Rule::in(['objective', 'essay', 'upload', 'mixed'])],
      'instructions' => ['nullable', 'string'],
      'objective' => ['nullable', 'string'],
      'rubric' => ['nullable', 'array'],
      'max_score' => ['nullable', 'integer', 'min:1'],
      'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
      'max_attempts' => ['nullable', 'integer', 'min:1'],
      'allow_resubmission' => ['sometimes', 'boolean'],
      'allow_attachments' => ['sometimes', 'boolean'],
      'max_attachments' => ['nullable', 'integer', 'min:0', 'max:20'],
      'due_at' => ['nullable', 'date'],
      'is_required' => ['sometimes', 'boolean'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived'])],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $assignment = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['assignment' => new AssignmentResource($assignment)],
      message: 'Assignment created.',
      status: 201,
    );
  }

  public function update(Request $request, Assignment $assignment, AssignmentService $service): JsonResponse
  {
    $this->authorize('update', $assignment->course);
    $validated = $request->validate([
      'lesson_id' => ['sometimes', 'nullable', 'uuid'],
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'type' => ['sometimes', 'string', Rule::in(['objective', 'essay', 'upload', 'mixed'])],
      'instructions' => ['sometimes', 'nullable', 'string'],
      'objective' => ['sometimes', 'nullable', 'string'],
      'rubric' => ['sometimes', 'nullable', 'array'],
      'max_score' => ['sometimes', 'integer', 'min:1'],
      'pass_mark' => ['sometimes', 'numeric', 'min:0', 'max:100'],
      'max_attempts' => ['sometimes', 'integer', 'min:1'],
      'allow_resubmission' => ['sometimes', 'boolean'],
      'allow_attachments' => ['sometimes', 'boolean'],
      'max_attachments' => ['sometimes', 'integer', 'min:0', 'max:20'],
      'due_at' => ['sometimes', 'nullable', 'date'],
      'is_required' => ['sometimes', 'boolean'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    $assignment = $service->update($assignment, $validated, $request->user());

    return $this->responder->success(
      data: ['assignment' => new AssignmentResource($assignment)],
      message: 'Assignment updated.',
    );
  }

  public function destroy(Request $request, Assignment $assignment, AssignmentService $service): JsonResponse
  {
    $this->authorize('update', $assignment->course);
    $service->delete($assignment, $request->user());

    return $this->responder->success(message: 'Assignment deleted.');
  }

  public function gradingQueue(Request $request, AssignmentService $service): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->gradingQueue((int) $request->query('per_page', 25)),
        AssignmentSubmissionResource::class,
      ),
      message: 'Assignment grading queue loaded.',
    );
  }

  public function grade(Request $request, AssignmentSubmission $submission, AssignmentService $service): JsonResponse
  {
    $submission->loadMissing('assignment.course');
    $this->authorize('update', $submission->assignment->course);
    $validated = $request->validate([
      'score' => ['required', 'numeric', 'min:0'],
      'teacher_comments' => ['nullable', 'string'],
      'return' => ['sometimes', 'boolean'],
    ]);

    $graded = $service->grade($submission, $request->user(), $validated);

    return $this->responder->success(
      data: ['submission' => new AssignmentSubmissionResource($graded)],
      message: 'Submission graded.',
    );
  }
}
