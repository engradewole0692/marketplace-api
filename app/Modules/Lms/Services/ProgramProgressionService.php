<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Lms\Models\SchoolEnrollment;
use Illuminate\Support\Collection;

final class ProgramProgressionService implements ServiceContract
{
  public function __construct(
    private readonly LmsAccessService $accessService,
    private readonly CommunicationLmsBridge $communicationLms,
  ) {}

  public function notifyModuleCompletionIfComplete(User $user, Course $completedCourse): void
  {
    $completedCourse->loadMissing('programModule');
    $module = $completedCourse->programModule;
    if ($module === null) {
      return;
    }

    if (! $this->moduleIsComplete($user, $module)) {
      return;
    }

    $this->communicationLms->notifyModuleCompleted($user, $module);
  }

  public function isSequentialEnabled(Course $course): bool
  {
    $course->loadMissing(['school', 'category', 'programModule']);

    if ($course->program_module_id === null) {
      return false;
    }

    if ($course->school_id !== null && $course->school !== null) {
      return (bool) ($course->school->sequential_progression ?? true);
    }

    if ($course->category instanceof CourseCategory && (bool) $course->category->is_free_learning_hub) {
      return true;
    }

    return false;
  }

  public function isCourseComplete(User $user, Course $course): bool
  {
    $enrollment = Enrollment::query()
      ->where('user_id', $user->id)
      ->where('course_id', $course->id)
      ->whereIn('status', [
        EnrollmentStatus::Active->value,
        EnrollmentStatus::Completed->value,
      ])
      ->first();

    if ($enrollment === null) {
      return false;
    }

    $status = $enrollment->status instanceof EnrollmentStatus
      ? $enrollment->status
      : EnrollmentStatus::tryFrom((string) $enrollment->status);

    return $status === EnrollmentStatus::Completed || (float) ($enrollment->progress_percent ?? 0) >= 100;
  }

  public function isCourseAccessible(User $user, Course $course): bool
  {
    if ($this->accessService->bypassesPaidLmsAccess($user)) {
      return true;
    }

    if (! $this->isSequentialEnabled($course)) {
      return true;
    }

    $course->loadMissing('programModule');
    if ($course->programModule === null) {
      return true;
    }

    foreach ($this->priorCoursesInContainer($course) as $priorCourse) {
      if (! $this->isCourseComplete($user, $priorCourse)) {
        return false;
      }
    }

    return true;
  }

  public function assertCourseAccessible(User $user, Course $course): void
  {
    if ($this->isCourseAccessible($user, $course)) {
      return;
    }

    throw new BusinessException(
      'Complete the required courses in the previous programme module before continuing.',
      ApiErrorCode::Forbidden,
      null,
      403,
    );
  }

  /** @return Collection<int, Course> */
  public function priorCoursesInContainer(Course $course): Collection
  {
    $course->loadMissing('programModule');
    $module = $course->programModule;
    if ($module === null) {
      return collect();
    }

    $priorModules = LmsProgramModule::query()
      ->where('container_type', $module->container_type)
      ->when($module->school_id !== null, fn ($q) => $q->where('school_id', $module->school_id))
      ->when($module->category_id !== null, fn ($q) => $q->where('category_id', $module->category_id))
      ->where('status', ModuleStatus::Published->value)
      ->where('sort_order', '<', $module->sort_order)
      ->orderBy('sort_order')
      ->pluck('id');

    if ($priorModules->isEmpty()) {
      return collect();
    }

    return Course::query()
      ->whereIn('program_module_id', $priorModules)
      ->where('status', CourseStatus::Published)
      ->orderBy('sort_order')
      ->get();
  }

  /** Enroll learner in newly unlocked programme courses after completing a course. */
  public function unlockNextCourses(User $user, Course $completedCourse): void
  {
    $completedCourse->loadMissing(['school', 'programModule']);
    if ($completedCourse->programModule === null) {
      return;
    }

    if ($completedCourse->school_id !== null) {
      $schoolEnrollment = SchoolEnrollment::query()
        ->where('school_id', $completedCourse->school_id)
        ->where('user_id', $user->id)
        ->whereIn('status', [
          EnrollmentStatus::Active->value,
          EnrollmentStatus::Completed->value,
        ])
        ->first();

      if ($schoolEnrollment === null) {
        return;
      }

      $nextCourses = $this->nextAccessibleCourses($user, $completedCourse);
      $enrollmentService = app(EnrollmentService::class);
      foreach ($nextCourses as $course) {
        try {
          $enrollmentService->enrollViaSchool($course, $user, $schoolEnrollment);
        } catch (BusinessException) {
          // Skip audience or access mismatches.
        }
      }

      return;
    }

    $enrollmentService = app(EnrollmentService::class);
    $learnerType = app(LearnerTypeResolver::class)->resolve($user);

    foreach ($this->nextAccessibleCourses($user, $completedCourse) as $course) {
      if (Enrollment::query()
        ->where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->where('status', '!=', EnrollmentStatus::Cancelled->value)
        ->exists()) {
        continue;
      }

      try {
        $enrollmentService->enroll($course, $user, $learnerType);
      } catch (BusinessException) {
        // Skip if enrolment rules block auto-enrol.
      }
    }
  }

  /** @return Collection<int, Course> */
  private function nextAccessibleCourses(User $user, Course $completedCourse): Collection
  {
    $completedCourse->loadMissing('programModule');
    $module = $completedCourse->programModule;
    if ($module === null) {
      return collect();
    }

    $query = Course::query()
      ->where('status', CourseStatus::Published)
      ->where('program_module_id', $module->id)
      ->where('id', '!=', $completedCourse->id);

    if ($module->school_id !== null) {
      $query->where('school_id', $module->school_id);
    } elseif ($module->category_id !== null) {
      $query->where('category_id', $module->category_id)->whereNull('school_id');
    }

    $sameModuleRemaining = $query->orderBy('sort_order')->get()
      ->filter(fn (Course $c) => ! $this->isCourseComplete($user, $c));

    if ($sameModuleRemaining->isNotEmpty()) {
      return $sameModuleRemaining->take(1);
    }

    if (! $this->moduleIsComplete($user, $module)) {
      return collect();
    }

    $nextModule = LmsProgramModule::query()
      ->where('container_type', $module->container_type)
      ->when($module->school_id !== null, fn ($q) => $q->where('school_id', $module->school_id))
      ->when($module->category_id !== null, fn ($q) => $q->where('category_id', $module->category_id))
      ->where('status', ModuleStatus::Published->value)
      ->where('sort_order', '>', $module->sort_order)
      ->orderBy('sort_order')
      ->first();

    if ($nextModule === null) {
      return collect();
    }

    return Course::query()
      ->where('program_module_id', $nextModule->id)
      ->where('status', CourseStatus::Published)
      ->orderBy('sort_order')
      ->get()
      ->filter(fn (Course $c) => $this->isCourseAccessible($user, $c));
  }

  private function moduleIsComplete(User $user, LmsProgramModule $module): bool
  {
    $courses = Course::query()
      ->where('program_module_id', $module->id)
      ->where('status', CourseStatus::Published)
      ->orderBy('sort_order')
      ->get();

    if ($courses->isEmpty()) {
      return true;
    }

    return $courses->every(fn (Course $course) => $this->isCourseComplete($user, $course));
  }

  /**
   * @return array{
   *   sequential: bool,
   *   course_locked: bool,
   *   program_module: array{id: string, title: string, locked: bool}|null
   * }
   */
  public function courseLockMeta(User $user, Course $course): array
  {
    $sequential = $this->isSequentialEnabled($course);
    $locked = $sequential && ! $this->isCourseAccessible($user, $course);
    $course->loadMissing('programModule');

    return [
      'sequential' => $sequential,
      'course_locked' => $locked,
      'program_module' => $course->programModule ? [
        'id' => $course->programModule->uuid,
        'title' => $course->programModule->title,
        'locked' => $locked,
      ] : null,
    ];
  }
}
