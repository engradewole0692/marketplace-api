<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgressStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use App\Modules\Lms\Models\SchoolEnrollment;
use Illuminate\Support\Facades\DB;

final class ProgressService implements ServiceContract
{
  public function __construct(
    private readonly CourseCertificateService $certificates,
    private readonly LmsNotificationService $notifications,
    private readonly ProgramProgressionService $programProgression,
  ) {}

  public function markLessonProgress(
    Enrollment $enrollment,
    Lesson $lesson,
    float $progressPercent,
    ?int $positionSeconds = null,
    ?int $timeSpentDeltaSeconds = null,
  ): LessonProgress {
    return DB::transaction(function () use ($enrollment, $lesson, $progressPercent, $positionSeconds, $timeSpentDeltaSeconds): LessonProgress {
      $row = LessonProgress::query()->firstOrNew([
        'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id,
      ]);

      $progressPercent = $this->sanitizeProgressPercent(
        $lesson,
        $row,
        $progressPercent,
        $positionSeconds,
        $timeSpentDeltaSeconds,
      );

      $threshold = (int) ($lesson->completion_threshold_percent ?: 75);
      $status = $progressPercent <= 0
        ? ProgressStatus::NotStarted
        : ($progressPercent >= $threshold ? ProgressStatus::Completed : ProgressStatus::InProgress);

      if (! $row->exists) {
        $row->started_at = now();
      }

      $timeSpent = (int) ($row->time_spent_seconds ?? 0);
      if ($timeSpentDeltaSeconds !== null && $timeSpentDeltaSeconds > 0) {
        $timeSpent += min(3600, $timeSpentDeltaSeconds);
      }

      $row->fill([
        'status' => $status,
        'progress_percent' => $progressPercent,
        'last_position_seconds' => $positionSeconds ?? $row->last_position_seconds ?? 0,
        'time_spent_seconds' => $timeSpent,
        'completed_at' => $status === ProgressStatus::Completed ? ($row->completed_at ?? now()) : null,
      ]);
      $row->save();

      $this->recomputeEnrollment($enrollment->fresh());

      return $row->fresh(['lesson']);
    });
  }

  private function sanitizeProgressPercent(
    Lesson $lesson,
    LessonProgress $existing,
    float $requestedPercent,
    ?int $positionSeconds,
    ?int $timeSpentDeltaSeconds,
  ): float {
    $previous = $existing->exists ? (float) $existing->progress_percent : 0.0;
    $percent = max($previous, min(100, $requestedPercent));

    $lessonType = $lesson->lesson_type instanceof LessonType
      ? $lesson->lesson_type
      : LessonType::tryFrom((string) ($lesson->lesson_type ?? 'text'));

    if ($lessonType !== LessonType::Video) {
      return round(max(0, min(100, $percent)), 2);
    }

    $durationMinutes = (int) ($lesson->duration_minutes ?? 0);
    if ($durationMinutes <= 0) {
      return round(max(0, min(100, $percent)), 2);
    }

    if ($positionSeconds !== null) {
      $durationSeconds = $durationMinutes * 60;
      $positionPercent = min(100, ($positionSeconds / max(1, $durationSeconds)) * 100);
      $percent = min($percent, $positionPercent + 8);
    }

    $threshold = (int) ($lesson->completion_threshold_percent ?: 75);
    if ($percent >= $threshold && $previous < max(0, $threshold - 5)) {
      $totalTime = (int) ($existing->time_spent_seconds ?? 0) + max(0, (int) ($timeSpentDeltaSeconds ?? 0));
      $durationSeconds = $durationMinutes * 60;
      $requiredTime = min($durationSeconds * 0.6, 900);
      if ($totalTime < $requiredTime) {
        $percent = min($percent, max($previous, $threshold - 1));
      }
    }

    return round(max(0, min(100, $percent)), 2);
  }

  public function recomputeEnrollment(Enrollment $enrollment): Enrollment
  {
    // Count curriculum lessons for progress. Prefer published lessons; if none are
    // published yet (common while building), fall back to all course lessons so
    // Mark Complete still advances enrollment % instead of forever staying at 0.
    $base = Lesson::query()->where('course_id', $enrollment->course_id);

    $mandatoryPublished = (clone $base)
      ->where('is_mandatory', true)
      ->where('status', ModuleStatus::Published)
      ->pluck('id');

    $mandatoryLessonIds = $mandatoryPublished->isNotEmpty()
      ? $mandatoryPublished
      : (clone $base)->where('is_mandatory', true)->pluck('id');

    if ($mandatoryLessonIds->isEmpty()) {
      $published = (clone $base)->where('status', ModuleStatus::Published)->pluck('id');
      $mandatoryLessonIds = $published->isNotEmpty()
        ? $published
        : (clone $base)->pluck('id');
    }

    $total = $mandatoryLessonIds->count();
    $completed = $total === 0
      ? 0
      : LessonProgress::query()
        ->where('enrollment_id', $enrollment->id)
        ->whereIn('lesson_id', $mandatoryLessonIds)
        ->where('status', ProgressStatus::Completed)
        ->count();

    $percent = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;

    $enrollment->forceFill(['progress_percent' => $percent])->save();

    if ($percent >= 100 && $enrollment->status === EnrollmentStatus::Active) {
      $enrollment->forceFill([
        'status' => EnrollmentStatus::Completed,
        'completed_at' => now(),
      ])->save();
      $this->notifications->notifyCourseCompletion($enrollment->fresh(['course', 'user']));
    }

    $freshEnrollment = $enrollment->fresh(['certificate', 'course', 'user']);
    if ($freshEnrollment->status === EnrollmentStatus::Completed && $freshEnrollment->course !== null && $freshEnrollment->user !== null) {
      $this->programProgression->unlockNextCourses($freshEnrollment->user, $freshEnrollment->course);
      $this->programProgression->notifyModuleCompletionIfComplete($freshEnrollment->user, $freshEnrollment->course);
    }

    // Auto-issue when course complete AND assessment pass (shared certificate engine).
    if ($percent >= 100 || $freshEnrollment->status === EnrollmentStatus::Completed) {
      $this->certificates->tryIssue($freshEnrollment);
    }

    $this->recomputeSchoolEnrollment($freshEnrollment->fresh(['course']));

    return $freshEnrollment->fresh(['certificate', 'course']);
  }

  private function recomputeSchoolEnrollment(Enrollment $enrollment): void
  {
    $course = $enrollment->course;
    if ($course === null || $course->school_id === null) {
      return;
    }

    $schoolEnrollment = SchoolEnrollment::query()
      ->where('school_id', $course->school_id)
      ->where('user_id', $enrollment->user_id)
      ->whereIn('status', [
        EnrollmentStatus::Active->value,
        EnrollmentStatus::Completed->value,
      ])
      ->first();

    if ($schoolEnrollment === null) {
      return;
    }

    $publishedCourseIds = Course::query()
      ->where('school_id', $course->school_id)
      ->where('status', 'published')
      ->pluck('id');

    if ($publishedCourseIds->isEmpty()) {
      return;
    }

    $courseEnrollments = Enrollment::query()
      ->where('user_id', $enrollment->user_id)
      ->whereIn('course_id', $publishedCourseIds)
      ->whereIn('status', [
        EnrollmentStatus::Active->value,
        EnrollmentStatus::Completed->value,
      ])
      ->get();

    if ($courseEnrollments->isEmpty()) {
      return;
    }

    $avgProgress = round((float) $courseEnrollments->avg('progress_percent'), 2);
    $allComplete = $publishedCourseIds->every(function (int $courseId) use ($courseEnrollments): bool {
      $match = $courseEnrollments->firstWhere('course_id', $courseId);

      return $match !== null && (
        $match->status === EnrollmentStatus::Completed
        || (float) $match->progress_percent >= 100
      );
    });

    $schoolEnrollment->forceFill([
      'progress_percent' => $avgProgress,
    ]);

    if ($allComplete) {
      $schoolEnrollment->forceFill([
        'status' => EnrollmentStatus::Completed,
        'completed_at' => $schoolEnrollment->completed_at ?? now(),
      ]);
    }

    $schoolEnrollment->save();
  }

  /** @deprecated Use CourseCertificateService::tryIssue / issue */
  public function issueCertificate(Enrollment $enrollment): CourseCertificate
  {
    $issued = $this->certificates->tryIssue($enrollment);
    if ($issued) {
      return $issued;
    }

    return $this->certificates->issue($enrollment);
  }

  public function maybeIssueCertificate(Enrollment $enrollment): ?CourseCertificate
  {
    return $this->certificates->tryIssue($enrollment);
  }

  public function verify(string $code): ?CourseCertificate
  {
    return CourseCertificate::query()
      ->where('verification_code', $code)
      ->where('status', 'issued')
      ->with(['course', 'user', 'certificateMedia'])
      ->first();
  }
}
