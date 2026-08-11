<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgressStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use Illuminate\Support\Collection;

final class CurriculumProgressionService implements ServiceContract
{
  public function isSequentialEnabled(Course $course): bool
  {
    $course->loadMissing('school');

    $metadata = is_array($course->metadata) ? $course->metadata : [];
    if (array_key_exists('sequential_progression', $metadata)) {
      return (bool) $metadata['sequential_progression'];
    }

    if ($course->school_id !== null && $course->school !== null) {
      return (bool) ($course->school->sequential_progression ?? true);
    }

    return false;
  }

  /** @return Collection<int, CourseModule> */
  public function orderedModules(Course $course): Collection
  {
    return CourseModule::query()
      ->where('course_id', $course->id)
      ->where('status', ModuleStatus::Published)
      ->orderBy('sort_order')
      ->with(['lessons' => fn ($q) => $q->where('status', ModuleStatus::Published)->orderBy('sort_order')])
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

  public function isModuleComplete(Enrollment $enrollment, CourseModule $module): bool
  {
    $lessons = $module->relationLoaded('lessons')
      ? $module->lessons
      : $module->lessons()->where('status', ModuleStatus::Published)->orderBy('sort_order')->get();

    $mandatory = $lessons->filter(fn (Lesson $l) => (bool) $l->is_mandatory);
    $targets = $mandatory->isNotEmpty() ? $mandatory : $lessons;

    if ($targets->isEmpty()) {
      return true;
    }

    return $targets->every(fn (Lesson $lesson) => $this->isLessonCompleted($enrollment, $lesson));
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

    foreach ($this->orderedLessons($course) as $candidate) {
      if ($candidate->id === $lesson->id) {
        return true;
      }

      if ((bool) $candidate->is_mandatory && ! $this->isLessonCompleted($enrollment, $candidate)) {
        return false;
      }
    }

    return false;
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

  /**
   * @return array{
   *   sequential: bool,
   *   modules: array<int, array{id: string, locked: bool, lessons: array<int, array{id: string, locked: bool}>}>
   * }
   */
  public function curriculumLockMap(Enrollment $enrollment, Course $course): array
  {
    $sequential = $this->isSequentialEnabled($course);
    $modules = $this->orderedModules($course);
    $payload = [];

    foreach ($modules as $module) {
      $moduleLocked = $sequential && ! $this->isModuleAccessible($enrollment, $module, $modules);
      $lessons = [];

      foreach ($module->lessons as $lesson) {
        $lessonLocked = $sequential && ($moduleLocked || ! $this->isLessonAccessible($enrollment, $lesson));
        $lessons[] = [
          'id' => $lesson->uuid,
          'locked' => $lessonLocked,
          'completed' => $this->isLessonCompleted($enrollment, $lesson),
        ];
      }

      $payload[] = [
        'id' => $module->uuid,
        'locked' => $moduleLocked,
        'lessons' => $lessons,
      ];
    }

    return [
      'sequential' => $sequential,
      'modules' => $payload,
    ];
  }
}
