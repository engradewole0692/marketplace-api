<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgressStatus;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use Illuminate\Support\Facades\DB;

final class ProgressService implements ServiceContract
{
  public function __construct(
    private readonly CourseCertificateService $certificates,
    private readonly LmsNotificationService $notifications,
  ) {}

  public function markLessonProgress(
    Enrollment $enrollment,
    Lesson $lesson,
    float $progressPercent,
    ?int $positionSeconds = null,
    ?int $timeSpentDeltaSeconds = null,
  ): LessonProgress {
    return DB::transaction(function () use ($enrollment, $lesson, $progressPercent, $positionSeconds, $timeSpentDeltaSeconds): LessonProgress {
      $progressPercent = max(0, min(100, $progressPercent));
      $threshold = (int) ($lesson->completion_threshold_percent ?: 100);
      $status = $progressPercent <= 0
        ? ProgressStatus::NotStarted
        : ($progressPercent >= $threshold ? ProgressStatus::Completed : ProgressStatus::InProgress);

      $row = LessonProgress::query()->firstOrNew([
        'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id,
      ]);

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

    // Auto-issue when course complete AND assessment pass (shared certificate engine).
    if ($percent >= 100 || $enrollment->fresh()->status === EnrollmentStatus::Completed) {
      $this->certificates->tryIssue($enrollment->fresh());
    }

    return $enrollment->fresh(['certificate', 'course']);
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
