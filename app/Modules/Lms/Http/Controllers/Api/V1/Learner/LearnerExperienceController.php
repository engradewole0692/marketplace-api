<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Models\Bookmark;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonNote;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\CurriculumProgressionService;
use App\Modules\Lms\Services\LearningExperienceService;
use App\Modules\Lms\Services\ProgramProgressionService;
use App\Modules\Lms\Services\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearnerExperienceController extends ApiController
{
  public function experience(Request $request, LearningExperienceService $service): JsonResponse
  {
    return $this->responder->success(
      data: $service->experienceDashboard($request->user()),
      message: 'Learning experience retrieved.',
    );
  }

  public function player(Request $request, string $enrollmentId, string $lessonId, LearningExperienceService $service, CurriculumProgressionService $progression, ProgramProgressionService $programProgression): JsonResponse
  {
    $enrollment = Enrollment::query()
      ->where('uuid', $enrollmentId)
      ->where('user_id', $request->user()->id)
      ->with('course')
      ->firstOrFail();
    $lesson = Lesson::query()->where('uuid', $lessonId)->firstOrFail();

    $status = $enrollment->status instanceof EnrollmentStatus
      ? $enrollment->status
      : EnrollmentStatus::tryFrom((string) $enrollment->status);
    if ($status === null || ! $status->canAccessPlayer()) {
      throw new BusinessException(
        'This enrollment is locked or inactive. Complete payment or contact support to continue.',
        ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    if ($enrollment->course !== null) {
      $programProgression->assertCourseAccessible($request->user(), $enrollment->course);
    }

    $progression->assertLessonAccessible($enrollment, $lesson);

    $enrollment->forceFill(['last_accessed_at' => now()])->save();

    return $this->responder->success(
      data: $service->playerPayload($request->user(), $enrollment, $lesson),
      message: 'Lesson player retrieved.',
    );
  }

  public function progress(Request $request, ProgressService $progress, LearningExperienceService $experience, CurriculumProgressionService $progression, ProgramProgressionService $programProgression): JsonResponse
  {
    $validated = $request->validate([
      'enrollment_id' => ['required', 'uuid'],
      'lesson_id' => ['required', 'uuid'],
      'progress_percent' => ['required', 'numeric', 'min:0', 'max:100'],
      'position_seconds' => ['nullable', 'integer', 'min:0'],
      'time_spent_delta_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
    ]);

    $enrollment = Enrollment::query()
      ->where('uuid', $validated['enrollment_id'])
      ->where('user_id', $request->user()->id)
      ->with('course')
      ->firstOrFail();
    $lesson = Lesson::query()->where('uuid', $validated['lesson_id'])->firstOrFail();

    if ($enrollment->course !== null) {
      $programProgression->assertCourseAccessible($request->user(), $enrollment->course);
    }

    $progression->assertLessonAccessible($enrollment, $lesson);

    $existing = $enrollment->lessonProgress()->where('lesson_id', $lesson->id)->first();
    $previousPercent = $existing ? (float) $existing->progress_percent : 0.0;
    $previousStatus = $existing
      ? (string) ($existing->status->value ?? $existing->status)
      : 'not_started';

    $row = $progress->markLessonProgress(
      $enrollment,
      $lesson,
      (float) $validated['progress_percent'],
      $validated['position_seconds'] ?? null,
      $validated['time_spent_delta_seconds'] ?? null,
    );

    $enrollment = $enrollment->fresh(['course', 'certificate']);
    $newStatus = (string) ($row->status->value ?? $row->status);
    $newPercent = (float) $row->progress_percent;

    if ($newStatus === 'completed' && $previousStatus !== 'completed') {
      $experience->recordActivity(
        $request->user(),
        'lesson.completed',
        'Completed lesson: '.$lesson->title,
        null,
        $enrollment->course,
        $enrollment,
        $lesson,
      );
    } elseif ($newPercent - $previousPercent >= 25) {
      $experience->recordActivity(
        $request->user(),
        'lesson.progress',
        'Progress on: '.$lesson->title,
        null,
        $enrollment->course,
        $enrollment,
        $lesson,
        ['progress_percent' => $newPercent],
      );
    }

    return $this->responder->success(
      data: [
        'progress' => [
          'id' => $row->uuid,
          'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
          'progress_percent' => (float) $row->progress_percent,
          'last_position_seconds' => (int) $row->last_position_seconds,
          'time_spent_seconds' => (int) $row->time_spent_seconds,
        ],
        'enrollment' => [
          'id' => $enrollment->uuid,
          'progress_percent' => (float) $enrollment->progress_percent,
          'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : $enrollment->status,
        ],
      ],
      message: 'Progress updated.',
    );
  }

  public function bookmarks(Request $request, LearningExperienceService $service): JsonResponse
  {
    return $this->responder->success(
      data: ['data' => $service->bookmarksForUser($request->user())->values()],
      message: 'Bookmarks retrieved.',
    );
  }

  public function storeBookmark(Request $request, LearningExperienceService $experience): JsonResponse
  {
    $validated = $request->validate([
      'lesson_id' => ['required', 'uuid'],
      'note' => ['nullable', 'string'],
      'label' => ['nullable', 'string', 'max:120'],
      'position_seconds' => ['nullable', 'integer', 'min:0'],
    ]);

    $lesson = Lesson::query()->where('uuid', $validated['lesson_id'])->firstOrFail();
    $bookmark = Bookmark::query()->updateOrCreate(
      ['user_id' => $request->user()->id, 'lesson_id' => $lesson->id],
      [
        'note' => $validated['note'] ?? null,
        'label' => $validated['label'] ?? null,
        'position_seconds' => $validated['position_seconds'] ?? null,
      ],
    );

    $experience->recordActivity(
      $request->user(),
      'bookmark.created',
      'Bookmarked: '.$lesson->title,
      $validated['label'] ?? null,
      $lesson->course,
      null,
      $lesson,
    );

    return $this->responder->success(
      data: [
        'bookmark' => [
          'id' => $bookmark->uuid,
          'note' => $bookmark->note,
          'label' => $bookmark->label,
          'position_seconds' => $bookmark->position_seconds,
        ],
      ],
      message: 'Bookmark saved.',
      status: 201,
    );
  }

  public function destroyBookmark(Request $request, string $bookmarkId): JsonResponse
  {
    Bookmark::query()
      ->where('uuid', $bookmarkId)
      ->where('user_id', $request->user()->id)
      ->delete();

    return $this->responder->success(message: 'Bookmark removed.');
  }

  public function notes(Request $request): JsonResponse
  {
    $notes = LessonNote::query()
      ->where('user_id', $request->user()->id)
      ->when($request->query('lesson_id'), function ($q) use ($request): void {
        $lessonId = Lesson::query()->where('uuid', $request->query('lesson_id'))->value('id');
        if ($lessonId) {
          $q->where('lesson_id', $lessonId);
        }
      })
      ->with(['lesson:id,uuid,title'])
      ->latest()
      ->limit(100)
      ->get()
      ->map(fn (LessonNote $n) => [
        'id' => $n->uuid,
        'title' => $n->title,
        'body' => $n->body,
        'position_seconds' => $n->position_seconds,
        'lesson' => $n->lesson ? ['id' => $n->lesson->uuid, 'title' => $n->lesson->title] : null,
        'updated_at' => $n->updated_at?->toIso8601String(),
      ]);

    return $this->responder->success(data: ['data' => $notes], message: 'Notes retrieved.');
  }

  public function storeNote(Request $request, LearningExperienceService $experience): JsonResponse
  {
    $validated = $request->validate([
      'lesson_id' => ['required', 'uuid'],
      'enrollment_id' => ['nullable', 'uuid'],
      'title' => ['nullable', 'string', 'max:255'],
      'body' => ['required', 'string'],
      'position_seconds' => ['nullable', 'integer', 'min:0'],
    ]);

    $lesson = Lesson::query()->where('uuid', $validated['lesson_id'])->firstOrFail();
    $enrollmentId = null;
    if (! empty($validated['enrollment_id'])) {
      $enrollmentId = Enrollment::query()
        ->where('uuid', $validated['enrollment_id'])
        ->where('user_id', $request->user()->id)
        ->value('id');
    }

    $note = LessonNote::query()->create([
      'user_id' => $request->user()->id,
      'lesson_id' => $lesson->id,
      'enrollment_id' => $enrollmentId,
      'title' => $validated['title'] ?? null,
      'body' => $validated['body'],
      'position_seconds' => $validated['position_seconds'] ?? null,
    ]);

    $experience->recordActivity(
      $request->user(),
      'note.created',
      'Note on: '.$lesson->title,
      null,
      $lesson->course,
      null,
      $lesson,
    );

    return $this->responder->success(
      data: [
        'note' => [
          'id' => $note->uuid,
          'title' => $note->title,
          'body' => $note->body,
          'position_seconds' => $note->position_seconds,
        ],
      ],
      message: 'Note saved.',
      status: 201,
    );
  }

  public function updateNote(Request $request, LessonNote $note): JsonResponse
  {
    abort_unless($note->user_id === $request->user()->id, 403);
    $validated = $request->validate([
      'title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'body' => ['sometimes', 'string'],
      'position_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
    ]);
    $note->fill($validated)->save();

    return $this->responder->success(
      data: [
        'note' => [
          'id' => $note->uuid,
          'title' => $note->title,
          'body' => $note->body,
          'position_seconds' => $note->position_seconds,
        ],
      ],
      message: 'Note updated.',
    );
  }

  public function destroyNote(Request $request, LessonNote $note): JsonResponse
  {
    abort_unless($note->user_id === $request->user()->id, 403);
    $note->delete();

    return $this->responder->success(message: 'Note deleted.');
  }

  public function downloads(Request $request, LearningExperienceService $service): JsonResponse
  {
    $courseIds = Enrollment::query()
      ->where('user_id', $request->user()->id)
      ->whereIn('status', ['active', 'completed'])
      ->pluck('course_id')
      ->all();

    return $this->responder->success(
      data: ['data' => $service->downloadsForCourses($courseIds)->values()],
      message: 'Downloads retrieved.',
    );
  }

  public function certificates(Request $request, LearningExperienceService $service): JsonResponse
  {
    return $this->responder->success(
      data: ['data' => $service->certificatesForUser($request->user())->values()],
      message: 'Certificates retrieved.',
    );
  }

  public function curriculum(Request $request, string $enrollmentId, LearningExperienceService $service): JsonResponse
  {
    $enrollment = Enrollment::query()
      ->where('uuid', $enrollmentId)
      ->where('user_id', $request->user()->id)
      ->firstOrFail();

    return $this->responder->success(
      data: $service->enrollmentCurriculum($request->user(), $enrollment),
      message: 'Enrollment curriculum retrieved.',
    );
  }

  public function schoolCurriculum(Request $request, string $school, LearningExperienceService $service): JsonResponse
  {
    $schoolModel = LmsSchool::query()->where('uuid', $school)->firstOrFail();
    $user = $request->user();

    $hasAccess = SchoolEnrollment::query()
      ->where('user_id', $user->id)
      ->where('school_id', $schoolModel->id)
      ->whereIn('status', ['active', 'completed'])
      ->exists()
      || Enrollment::query()
        ->where('user_id', $user->id)
        ->whereHas('course', fn ($query) => $query->where('school_id', $schoolModel->id))
        ->exists();

    abort_unless($hasAccess, 404);

    return $this->responder->success(
      data: $service->schoolCurriculum($user, $schoolModel),
      message: 'School curriculum retrieved.',
    );
  }
}
