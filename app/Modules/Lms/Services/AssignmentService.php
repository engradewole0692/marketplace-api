<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Modules\Lms\Enums\AssignmentSubmissionStatus;
use App\Modules\Lms\Enums\AssignmentType;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Models\Assignment;
use App\Modules\Lms\Models\AssignmentSubmission;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssignmentService implements ServiceContract
{
  public function __construct(
    private readonly LmsAuditService $auditService,
    private readonly LmsNotificationService $notificationService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateAdmin(array $filters = []): LengthAwarePaginator
  {
    $query = Assignment::query()
      ->with(['course:id,uuid,title', 'lesson:id,uuid,title'])
      ->withCount('submissions')
      ->latest('id');

    if (! empty($filters['course_id'])) {
      $courseId = Course::query()->where('uuid', $filters['course_id'])->value('id');
      if ($courseId) {
        $query->where('course_id', $courseId);
      }
    }
    if (! empty($filters['type'])) {
      $query->where('type', $filters['type']);
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Assignment
  {
    $course = Course::query()->where('uuid', $data['course_id'])->firstOrFail();
    $slug = Str::slug((string) ($data['slug'] ?? $data['title']));
    if ($slug === '') {
      $slug = 'assignment-'.Str::lower(Str::random(6));
    }

    $assignment = Assignment::query()->create([
      'course_id' => $course->id,
      'lesson_id' => isset($data['lesson_id'])
        ? Lesson::query()->where('uuid', $data['lesson_id'])->value('id')
        : null,
      'title' => $data['title'],
      'slug' => $slug,
      'type' => $data['type'] ?? AssignmentType::Mixed->value,
      'instructions' => $data['instructions'] ?? null,
      'objective' => $data['objective'] ?? null,
      'rubric' => $data['rubric'] ?? null,
      'max_score' => (int) ($data['max_score'] ?? 100),
      'pass_mark' => (float) ($data['pass_mark'] ?? 70),
      'max_attempts' => (int) ($data['max_attempts'] ?? 3),
      'allow_resubmission' => (bool) ($data['allow_resubmission'] ?? true),
      'allow_attachments' => (bool) ($data['allow_attachments'] ?? true),
      'max_attachments' => (int) ($data['max_attachments'] ?? 5),
      'due_at' => $data['due_at'] ?? null,
      'is_required' => (bool) ($data['is_required'] ?? true),
      'status' => $data['status'] ?? 'published',
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'created_by_user_id' => $actor->id,
    ]);

    $this->auditService->record($course, $actor, 'assignment.created', 'Assignment created.', null, [
      'assignment_id' => $assignment->uuid,
      'title' => $assignment->title,
    ]);

    return $assignment->fresh(['course', 'lesson']);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Assignment $assignment, array $data, User $actor): Assignment
  {
    $payload = collect($data)->only([
      'title', 'slug', 'type', 'instructions', 'objective', 'rubric', 'max_score',
      'pass_mark', 'max_attempts', 'allow_resubmission', 'allow_attachments',
      'max_attachments', 'due_at', 'is_required', 'status', 'sort_order',
    ])->all();

    if (isset($payload['slug'])) {
      $payload['slug'] = Str::slug((string) $payload['slug']);
    }
    if (array_key_exists('lesson_id', $data)) {
      $payload['lesson_id'] = $data['lesson_id']
        ? Lesson::query()->where('uuid', $data['lesson_id'])->value('id')
        : null;
    }

    $assignment->fill($payload)->save();
    $this->auditService->record($assignment->course, $actor, 'assignment.updated', 'Assignment updated.', null, [
      'assignment_id' => $assignment->uuid,
    ]);

    return $assignment->fresh(['course', 'lesson']);
  }

  public function delete(Assignment $assignment, User $actor): void
  {
    $course = $assignment->course;
    $uuid = $assignment->uuid;
    $assignment->delete();
    $this->auditService->record($course, $actor, 'assignment.deleted', 'Assignment deleted.', null, [
      'assignment_id' => $uuid,
    ]);
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function forLearner(User $user): array
  {
    $enrollmentIds = Enrollment::query()
      ->where('user_id', $user->id)
      ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
      ->pluck('id', 'course_id');

    if ($enrollmentIds->isEmpty()) {
      return [];
    }

    $assignments = Assignment::query()
      ->with(['course:id,uuid,title,slug'])
      ->whereIn('course_id', $enrollmentIds->keys())
      ->where('status', 'published')
      ->orderBy('sort_order')
      ->orderBy('id')
      ->get();

    return $assignments->map(function (Assignment $assignment) use ($user, $enrollmentIds): array {
      $enrollmentId = (int) $enrollmentIds->get($assignment->course_id);
      $latest = AssignmentSubmission::query()
        ->where('assignment_id', $assignment->id)
        ->where('enrollment_id', $enrollmentId)
        ->where('user_id', $user->id)
        ->latest('attempt_number')
        ->first();

      return [
        'id' => $assignment->uuid,
        'title' => $assignment->title,
        'type' => $assignment->type instanceof AssignmentType ? $assignment->type->value : (string) $assignment->type,
        'instructions' => $assignment->instructions,
        'objective' => $assignment->objective,
        'due_at' => $assignment->due_at?->toIso8601String(),
        'pass_mark' => (float) $assignment->pass_mark,
        'max_score' => (int) $assignment->max_score,
        'max_attempts' => (int) $assignment->max_attempts,
        'allow_resubmission' => (bool) $assignment->allow_resubmission,
        'allow_attachments' => (bool) $assignment->allow_attachments,
        'is_required' => (bool) $assignment->is_required,
        'course' => $assignment->course ? [
          'id' => $assignment->course->uuid,
          'title' => $assignment->course->title,
          'slug' => $assignment->course->slug,
        ] : null,
        'enrollment_id' => Enrollment::query()->whereKey($enrollmentId)->value('uuid'),
        'submission' => $latest ? [
          'id' => $latest->uuid,
          'status' => $latest->status instanceof AssignmentSubmissionStatus
            ? $latest->status->value
            : (string) $latest->status,
          'attempt_number' => (int) $latest->attempt_number,
          'score' => $latest->score !== null ? (float) $latest->score : null,
          'submitted_at' => $latest->submitted_at?->toIso8601String(),
          'teacher_comments' => $latest->teacher_comments,
        ] : null,
      ];
    })->all();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function submit(Assignment $assignment, Enrollment $enrollment, User $user, array $data): AssignmentSubmission
  {
    if ($enrollment->user_id !== $user->id) {
      throw new BusinessException('Enrollment does not belong to this user.', ApiErrorCode::Forbidden, null, 403);
    }
    if ($enrollment->course_id !== $assignment->course_id) {
      throw new BusinessException('Assignment does not belong to this enrollment course.', ApiErrorCode::UnprocessableEntity, null, 422);
    }
    if (! $enrollment->status?->canAccessPlayer()) {
      throw new BusinessException('Enrollment is not active for submissions.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    $attempts = AssignmentSubmission::query()
      ->where('assignment_id', $assignment->id)
      ->where('enrollment_id', $enrollment->id)
      ->count();

    $latest = AssignmentSubmission::query()
      ->where('assignment_id', $assignment->id)
      ->where('enrollment_id', $enrollment->id)
      ->latest('attempt_number')
      ->first();

    if ($latest && $latest->status === AssignmentSubmissionStatus::Submitted) {
      throw new BusinessException('A submission is already awaiting review.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    $creatingNew = $latest === null
      || in_array($latest->status, [AssignmentSubmissionStatus::Passed, AssignmentSubmissionStatus::Failed, AssignmentSubmissionStatus::Returned], true);

    if ($creatingNew && $attempts >= (int) $assignment->max_attempts) {
      throw new BusinessException('Maximum assignment attempts reached.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    if ($creatingNew && $latest !== null && ! $assignment->allow_resubmission) {
      throw new BusinessException('Resubmission is not allowed for this assignment.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    $attachments = $data['attachments'] ?? [];
    if (! $assignment->allow_attachments && ! empty($attachments)) {
      throw new BusinessException('Attachments are not allowed for this assignment.', ApiErrorCode::UnprocessableEntity, null, 422);
    }
    if (is_array($attachments) && count($attachments) > (int) $assignment->max_attachments) {
      throw new BusinessException('Too many attachments.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    return DB::transaction(function () use ($assignment, $enrollment, $user, $data, $attachments, $creatingNew, $latest, $attempts): AssignmentSubmission {
      if ($creatingNew) {
        $submission = AssignmentSubmission::query()->create([
          'assignment_id' => $assignment->id,
          'enrollment_id' => $enrollment->id,
          'user_id' => $user->id,
          'attempt_number' => $attempts + 1,
          'status' => AssignmentSubmissionStatus::Submitted,
          'essay_body' => $data['essay_body'] ?? null,
          'objective_answers' => $data['objective_answers'] ?? null,
          'attachments' => $attachments,
          'max_score' => $assignment->max_score,
          'submitted_at' => now(),
        ]);
      } else {
        $submission = $latest;
        $submission->forceFill([
          'status' => AssignmentSubmissionStatus::Submitted,
          'essay_body' => $data['essay_body'] ?? $submission->essay_body,
          'objective_answers' => $data['objective_answers'] ?? $submission->objective_answers,
          'attachments' => $attachments ?: $submission->attachments,
          'submitted_at' => now(),
          'returned_at' => null,
          'teacher_comments' => null,
          'score' => null,
          'graded_at' => null,
          'graded_by_user_id' => null,
        ])->save();
      }

      $this->notificationService->notifyAssignmentSubmitted($assignment, $submission->fresh(), $user);

      return $submission->fresh(['assignment']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function grade(AssignmentSubmission $submission, User $grader, array $data): AssignmentSubmission
  {
    $score = (float) ($data['score'] ?? 0);
    $max = (float) ($submission->max_score ?? $submission->assignment?->max_score ?? 100);
    $passMark = (float) ($submission->assignment?->pass_mark ?? 70);
    $percent = $max > 0 ? ($score / $max) * 100 : 0;
    $status = $percent >= $passMark
      ? AssignmentSubmissionStatus::Passed
      : AssignmentSubmissionStatus::Failed;

    if (($data['return'] ?? false) === true) {
      $status = AssignmentSubmissionStatus::Returned;
    }

    $submission->forceFill([
      'score' => $score,
      'status' => $status,
      'teacher_comments' => $data['teacher_comments'] ?? $submission->teacher_comments,
      'graded_at' => now(),
      'graded_by_user_id' => $grader->id,
      'returned_at' => $status === AssignmentSubmissionStatus::Returned ? now() : $submission->returned_at,
    ])->save();

    $this->notificationService->notifyAssignmentGraded($submission->assignment, $submission->fresh(), $grader);

    return $submission->fresh(['assignment', 'user']);
  }

  public function gradingQueue(int $perPage = 25): LengthAwarePaginator
  {
    return AssignmentSubmission::query()
      ->with(['assignment.course', 'user', 'enrollment'])
      ->where('status', AssignmentSubmissionStatus::Submitted->value)
      ->latest('submitted_at')
      ->paginate(min(100, max(1, $perPage)));
  }
}
