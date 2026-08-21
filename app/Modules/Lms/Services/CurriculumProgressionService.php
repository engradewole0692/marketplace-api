<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgressStatus;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use Illuminate\Support\Collection;

/**
 * Hierarchical course curriculum progression:
 * Course → ordered Modules → ordered Lessons (+ module assessments).
 *
 * Module locking is OFF by default so enrolled learners can open any module.
 * Opt-in sequential unlock via course.metadata.sequential_progression=true
 * or school.sequential_progression=true for school courses.
 */
final class CurriculumProgressionService implements ServiceContract
{
  public function isSequentialEnabled(Course $course): bool
  {
    $course->loadMissing('school');

    $metadata = is_array($course->metadata) ? $course->metadata : [];
    // Explicit course opt-in/out always wins.
    if (array_key_exists('sequential_progression', $metadata)) {
      return (bool) $metadata['sequential_progression'];
    }

    // School courses may opt into locking via school.sequential_progression=true.
    if ($course->school_id !== null && $course->school !== null) {
      return (bool) ($course->school->sequential_progression ?? false);
    }

    // Default: every module is available for active enrollments.
    return false;
  }

  /** @return Collection<int, CourseModule> */
  public function orderedModules(Course $course): Collection
  {
    return CourseModule::query()
      ->where('course_id', $course->id)
      ->where('status', ModuleStatus::Published)
      ->orderBy('sort_order')
      ->with([
        'lessons' => fn ($q) => $q->where('status', ModuleStatus::Published)->orderBy('sort_order'),
        'assessments' => fn ($q) => $q->where('status', AssessmentStatus::Published)->whereNull('lesson_id'),
      ])
      ->get();
  }

  /** @return Collection<int, Lesson> */
  public function orderedLessons(Course $course): Collection
  {
    return $this->orderedModules($course)->flatMap(fn (CourseModule $module) => $module->lessons)->values();
  }

  public function isLessonCompleted(Enrollment $enrollment, Lesson $lesson): bool
  {
    return LessonProgress::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('lesson_id', $lesson->id)
      ->where('status', ProgressStatus::Completed)
      ->exists();
  }

  public function isLessonInProgress(Enrollment $enrollment, Lesson $lesson): bool
  {
    return LessonProgress::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('lesson_id', $lesson->id)
      ->where('status', ProgressStatus::InProgress)
      ->exists();
  }

  public function hasPassedModuleAssessment(Enrollment $enrollment, Assessment $assessment): bool
  {
    return AssessmentAttempt::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('assessment_id', $assessment->id)
      ->where('passed', true)
      ->exists();
  }

  /**
   * Module is complete when all mandatory (or all) published lessons are completed
   * and any published module-level assessments (no lesson_id) have been passed.
   */
  public function isModuleComplete(Enrollment $enrollment, CourseModule $module): bool
  {
    $lessons = $module->relationLoaded('lessons')
      ? $module->lessons
      : $module->lessons()->where('status', ModuleStatus::Published)->orderBy('sort_order')->get();

    $mandatory = $lessons->filter(fn (Lesson $l) => (bool) $l->is_mandatory);
    $targets = $mandatory->isNotEmpty() ? $mandatory : $lessons;

    if ($targets->isNotEmpty() && ! $targets->every(fn (Lesson $lesson) => $this->isLessonCompleted($enrollment, $lesson))) {
      return false;
    }

    $assessments = $module->relationLoaded('assessments')
      ? $module->assessments
      : Assessment::query()
        ->where('module_id', $module->id)
        ->whereNull('lesson_id')
        ->where('status', AssessmentStatus::Published)
        ->get();

    foreach ($assessments as $assessment) {
      if (! $this->hasPassedModuleAssessment($enrollment, $assessment)) {
        return false;
      }
    }

    return true;
  }

  public function isModuleAccessible(Enrollment $enrollment, CourseModule $module, ?Collection $modules = null): bool
  {
    $course = $enrollment->course ?? Course::query()->find($enrollment->course_id);
    if ($course === null || ! $this->isSequentialEnabled($course)) {
      return true;
    }

    $modules ??= $this->orderedModules($course);

    foreach ($modules as $candidate) {
      if ($candidate->id === $module->id) {
        return true;
      }

      if (! $this->isModuleComplete($enrollment, $candidate)) {
        return false;
      }
    }

    return true;
  }

  public function isLessonAccessible(Enrollment $enrollment, Lesson $lesson): bool
  {
    $course = $enrollment->course ?? Course::query()->find($enrollment->course_id);
    if ($course === null || ! $this->isSequentialEnabled($course)) {
      return true;
    }

    $lesson->loadMissing('module');
    if ($lesson->module !== null && ! $this->isModuleAccessible($enrollment, $lesson->module)) {
      return false;
    }

    foreach ($this->orderedLessons($course) as $candidate) {
      if ($candidate->id === $lesson->id) {
        return true;
      }

      if ((bool) $candidate->is_mandatory && ! $this->isLessonCompleted($enrollment, $candidate)) {
        return false;
      }
    }

    // Not in the published curriculum sequence (e.g. draft lesson) — no sequential lock.
    return true;
  }

  /**
   * Access state for UI: locked | available | in_progress | completed.
   */
  public function moduleAccessState(Enrollment $enrollment, CourseModule $module, ?Collection $modules = null): string
  {
    if (! $this->isModuleAccessible($enrollment, $module, $modules)) {
      return 'locked';
    }

    if ($this->isModuleComplete($enrollment, $module)) {
      return 'completed';
    }

    $lessons = $module->relationLoaded('lessons')
      ? $module->lessons
      : $module->lessons()->where('status', ModuleStatus::Published)->get();

    $hasProgress = $lessons->contains(
      fn (Lesson $lesson) => $this->isLessonCompleted($enrollment, $lesson)
        || $this->isLessonInProgress($enrollment, $lesson),
    );

    return $hasProgress ? 'in_progress' : 'available';
  }

  public function lessonAccessState(Enrollment $enrollment, Lesson $lesson): string
  {
    if (! $this->isLessonAccessible($enrollment, $lesson)) {
      return 'locked';
    }

    if ($this->isLessonCompleted($enrollment, $lesson)) {
      return 'completed';
    }

    if ($this->isLessonInProgress($enrollment, $lesson)) {
      return 'in_progress';
    }

    return 'available';
  }

  public function assertLessonAccessible(Enrollment $enrollment, Lesson $lesson): void
  {
    if ($this->isLessonAccessible($enrollment, $lesson)) {
      return;
    }

    throw new BusinessException(
      'Complete the previous required lesson or module before continuing.',
      ApiErrorCode::Forbidden,
      null,
      403,
    );
  }

  public function assertModuleAccessible(Enrollment $enrollment, CourseModule $module): void
  {
    if ($this->isModuleAccessible($enrollment, $module)) {
      return;
    }

    throw new BusinessException(
      'This module is locked. Complete the previous module before continuing.',
      ApiErrorCode::Forbidden,
      null,
      403,
    );
  }

  public function assertAssessmentAccessible(Enrollment $enrollment, Assessment $assessment): void
  {
    $assessment->loadMissing(['lesson', 'module']);

    if ($assessment->lesson !== null) {
      $this->assertLessonAccessible($enrollment, $assessment->lesson);

      return;
    }

    if ($assessment->module !== null) {
      $this->assertModuleAccessible($enrollment, $assessment->module);

      return;
    }

    // Course-level assessment: allow once enrolled (no module lock).
  }

  /**
   * @return array{
   *   sequential: bool,
   *   current_module_id: string|null,
   *   modules: array<int, array{
   *     id: string,
   *     locked: bool,
   *     access_state: string,
   *     completed: bool,
   *     lessons: array<int, array{id: string, locked: bool, completed: bool, access_state: string}>,
   *     assessments: array<int, array{id: string, locked: bool, completed: bool, access_state: string}>
   *   }>
   * }
   */
  public function curriculumLockMap(Enrollment $enrollment, Course $course): array
  {
    $sequential = $this->isSequentialEnabled($course);
    $modules = $this->orderedModules($course);
    $payload = [];
    $currentModuleId = null;

    foreach ($modules as $module) {
      $accessState = $this->moduleAccessState($enrollment, $module, $modules);
      $moduleLocked = $accessState === 'locked';
      $moduleCompleted = $accessState === 'completed';

      if ($currentModuleId === null && in_array($accessState, ['available', 'in_progress'], true)) {
        $currentModuleId = $module->uuid;
      }

      $lessons = [];
      foreach ($module->lessons as $lesson) {
        $lessonState = $this->lessonAccessState($enrollment, $lesson);
        $lessons[] = [
          'id' => $lesson->uuid,
          'locked' => $lessonState === 'locked',
          'completed' => $lessonState === 'completed',
          'access_state' => $lessonState,
        ];
      }

      $assessments = [];
      foreach ($module->assessments as $assessment) {
        $assessmentLocked = $moduleLocked;
        $assessmentCompleted = $this->hasPassedModuleAssessment($enrollment, $assessment);
        $assessmentState = $assessmentLocked
          ? 'locked'
          : ($assessmentCompleted ? 'completed' : 'available');
        $assessments[] = [
          'id' => $assessment->uuid,
          'locked' => $assessmentLocked,
          'completed' => $assessmentCompleted,
          'access_state' => $assessmentState,
        ];
      }

      $payload[] = [
        'id' => $module->uuid,
        'locked' => $moduleLocked,
        'access_state' => $accessState,
        'completed' => $moduleCompleted,
        'lessons' => $lessons,
        'assessments' => $assessments,
      ];
    }

    if ($currentModuleId === null && $payload !== []) {
      $last = $payload[array_key_last($payload)];
      $currentModuleId = $last['completed'] ? null : $last['id'];
    }

    return [
      'sequential' => $sequential,
      'current_module_id' => $currentModuleId,
      'modules' => $payload,
    ];
  }
}
