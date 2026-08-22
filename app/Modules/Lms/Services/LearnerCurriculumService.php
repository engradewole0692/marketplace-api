<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgramModuleContainerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use Illuminate\Support\Collection;

/**
 * Assembles School → programme module → course trees from the database.
 * Programme modules (lms_program_modules) are the school curriculum units.
 * Course modules (lms_modules) are lesson groups inside a course and must not
 * be used as school module numbers.
 */
final class LearnerCurriculumService implements ServiceContract
{
  private function enumValue(mixed $value): string
  {
    if ($value instanceof \BackedEnum) {
      return (string) $value->value;
    }

    return (string) $value;
  }

  /**
   * @param  Collection<int, Enrollment>  $enrollments
   * @param  Collection<int, SchoolEnrollment>  $schoolEnrollments
   * @return array<string, mixed>
   */
  public function learningTree(Collection $enrollments, Collection $schoolEnrollments): array
  {
    $enrollmentsByCourseId = $enrollments
      ->filter(fn (Enrollment $enrollment) => $enrollment->course_id !== null)
      ->keyBy('course_id');

    $schoolNodes = $this->schoolNodes($enrollments, $schoolEnrollments);
    $freeCategories = $this->freeCategoryNodes($enrollments, $enrollmentsByCourseId);

    $freeCourseIds = collect($freeCategories)
      ->flatMap(function (array $category) {
        $moduleCourses = collect($category['modules'] ?? [])->flatMap(fn (array $module) => $module['courses'] ?? []);
        $unassigned = collect($category['unassigned_courses'] ?? []);

        return $moduleCourses->concat($unassigned);
      })
      ->map(fn (array $course) => $course['id'] ?? null)
      ->filter()
      ->all();

    $standalone = $enrollments
      ->filter(function (Enrollment $enrollment) use ($freeCourseIds): bool {
        $course = $enrollment->course;
        if ($course === null || $course->school_id !== null) {
          return false;
        }

        return ! in_array($course->uuid, $freeCourseIds, true);
      })
      ->map(fn (Enrollment $enrollment) => $this->courseNode($enrollment->course, $enrollment))
      ->filter()
      ->values();

    $allSchoolCourses = collect($schoolNodes)->flatMap(function (array $school) {
      $moduleCourses = collect($school['modules'] ?? [])->flatMap(fn (array $module) => $module['courses'] ?? []);

      return $moduleCourses->concat($school['unassigned_courses'] ?? []);
    });
    $allFreeCourses = collect($freeCategories)->flatMap(function (array $category) {
      $moduleCourses = collect($category['modules'] ?? [])->flatMap(fn (array $module) => $module['courses'] ?? []);

      return $moduleCourses->concat($category['unassigned_courses'] ?? []);
    });
    $allCourses = $allSchoolCourses->concat($allFreeCourses)->concat($standalone);
    $allModules = collect($schoolNodes)
      ->flatMap(fn (array $school) => $school['modules'] ?? [])
      ->concat(collect($freeCategories)->flatMap(fn (array $category) => $category['modules'] ?? []));

    $modulesCompleted = $allModules->filter(function (array $module): bool {
      $total = (int) ($module['courses_count'] ?? 0);

      return $total > 0 && (int) ($module['courses_completed'] ?? 0) === $total;
    })->count();

    return [
      'summary' => [
        'schools_enrolled' => count($schoolNodes),
        'courses_enrolled' => $enrollments->count(),
        'courses_completed' => $allCourses->where('status', 'completed')->count(),
        'courses_in_progress' => $allCourses->where('status', 'in_progress')->count(),
        'courses_remaining' => $allCourses->where('status', 'not_started')->count(),
        'modules_completed' => $modulesCompleted,
        'modules_remaining' => max(0, $allModules->count() - $modulesCompleted),
        'overall_progress' => round((float) ($enrollments->avg('progress_percent') ?? 0), 1),
        'certificates' => $enrollments->filter(fn (Enrollment $enrollment) => $enrollment->certificate !== null)->count(),
      ],
      'schools' => $schoolNodes,
      'free_categories' => $freeCategories,
      'standalone' => $standalone->all(),
    ];
  }

  /**
   * Full school curriculum for a learner who has school or course access.
   *
   * @param  Collection<int, Enrollment>|null  $enrollments
   * @return array<string, mixed>
   */
  public function schoolCurriculumForLearner(User $user, LmsSchool $school, ?Collection $enrollments = null): array
  {
    $enrollments ??= Enrollment::query()
      ->where('user_id', $user->id)
      ->whereIn('status', ['active', 'completed'])
      ->with(['course.programModule', 'lessonProgress.lesson', 'certificate'])
      ->get();

    $schoolEnrollment = SchoolEnrollment::query()
      ->where('user_id', $user->id)
      ->where('school_id', $school->id)
      ->whereIn('status', ['active', 'completed'])
      ->first();

    $includeAll = $schoolEnrollment !== null;
    $node = $this->buildSchoolNode($school, $schoolEnrollment, $enrollments, $includeAll);

    return [
      'school' => $node['school'],
      'enrollment' => $schoolEnrollment ? [
        'id' => $schoolEnrollment->uuid,
        'status' => $this->enumValue($schoolEnrollment->status),
        'progress_percent' => $node['progress_percent'],
      ] : null,
      'courses_count' => $node['courses_count'],
      'courses_completed' => $node['courses_completed'],
      'courses_in_progress' => $node['courses_in_progress'],
      'courses_remaining' => $node['courses_remaining'],
      'modules_count' => $node['modules_count'],
      'modules_completed' => $node['modules_completed'],
      'modules_remaining' => $node['modules_remaining'],
      'progress_percent' => $node['progress_percent'],
      'last_activity_at' => $node['last_activity_at'] ?? null,
      'current_course' => $node['current_course'] ?? null,
      'modules' => $node['modules'],
      'unassigned_courses' => $node['unassigned_courses'],
    ];
  }

  /** @return array{id: string, number: int, title: string, description: string|null}|null */
  public function programModuleRef(?LmsProgramModule $module): ?array
  {
    if ($module === null) {
      return null;
    }

    return [
      'id' => $module->uuid,
      'number' => (int) $module->sort_order,
      'title' => $module->title,
      'description' => $module->description,
    ];
  }

  /**
   * @param  Collection<int, Enrollment>  $enrollments
   * @param  Collection<int, SchoolEnrollment>  $schoolEnrollments
   * @return list<array<string, mixed>>
   */
  private function schoolNodes(
    Collection $enrollments,
    Collection $schoolEnrollments,
  ): array {
    $nodes = $schoolEnrollments->map(function (SchoolEnrollment $enrollment) use ($enrollments) {
      $school = $enrollment->school;
      if ($school === null) {
        return null;
      }

      return $this->buildSchoolNode($school, $enrollment, $enrollments, true);
    })->filter()->values();

    $knownSchoolIds = $nodes->map(fn (array $node) => $node['school']['id'] ?? null)->filter();

    $extraSchoolIds = $enrollments
      ->map(fn (Enrollment $enrollment) => $enrollment->course?->school)
      ->filter()
      ->unique('id')
      ->reject(fn (LmsSchool $school) => $knownSchoolIds->contains($school->uuid));

    foreach ($extraSchoolIds as $school) {
      $nodes->push($this->buildSchoolNode($school, null, $enrollments, false));
    }

    return $nodes->values()->all();
  }

  /**
   * @param  Collection<int, Enrollment>  $enrollments
   * @return array<string, mixed>
   */
  private function buildSchoolNode(
    LmsSchool $school,
    ?SchoolEnrollment $schoolEnrollment,
    Collection $enrollments,
    bool $includeAllPublishedCourses,
  ): array {
    $programModules = LmsProgramModule::query()
      ->where('container_type', ProgramModuleContainerType::School)
      ->where('school_id', $school->id)
      ->where('status', ModuleStatus::Published)
      ->orderBy('sort_order')
      ->orderBy('id')
      ->get();

    $coursesQuery = Course::query()
      ->where('school_id', $school->id)
      ->where('status', CourseStatus::Published)
      ->with($this->courseCurriculumRelations())
      ->orderBy('sort_order')
      ->orderBy('id');

    if (! $includeAllPublishedCourses) {
      $enrolledIds = $enrollments
        ->filter(fn (Enrollment $enrollment) => $enrollment->course?->school_id === $school->id)
        ->pluck('course_id')
        ->all();
      $coursesQuery->whereIn('id', $enrolledIds ?: [0]);
    }

    $courses = $coursesQuery->get();
    $enrollmentsByCourseId = $enrollments->keyBy('course_id');

    $moduleNodes = $programModules->map(function (LmsProgramModule $module) use ($courses, $enrollmentsByCourseId) {
      $moduleCourses = $courses
        ->where('program_module_id', $module->id)
        ->sortBy([
          ['sort_order', 'asc'],
          ['id', 'asc'],
        ])
        ->values()
        ->map(fn (Course $course) => $this->courseNode($course, $enrollmentsByCourseId->get($course->id)))
        ->all();

      return $this->moduleNode($module, $moduleCourses);
    })->values();

    $assignedIds = $programModules->pluck('id')->all();
    $unassigned = $courses
      ->filter(fn (Course $course) => $course->program_module_id === null || ! in_array($course->program_module_id, $assignedIds, true))
      ->values()
      ->map(fn (Course $course) => $this->courseNode($course, $enrollmentsByCourseId->get($course->id)))
      ->all();

    $allCourseNodes = collect($moduleNodes->flatMap(fn (array $module) => $module['courses'] ?? [])->all())
      ->concat($unassigned);
    $completed = $allCourseNodes->where('status', 'completed')->count();
    $inProgress = $allCourseNodes->where('status', 'in_progress')->count();
    $notStarted = $allCourseNodes->where('status', 'not_started')->count();
    $total = $allCourseNodes->count();
    $modulesCompleted = $moduleNodes->filter(function (array $module): bool {
      $count = (int) ($module['courses_count'] ?? 0);

      return $count > 0 && (int) ($module['courses_completed'] ?? 0) === $count;
    })->count();

    $progress = $schoolEnrollment?->progress_percent !== null
      ? (float) $schoolEnrollment->progress_percent
      : ($total > 0 ? round((float) $allCourseNodes->avg('progress_percent'), 1) : 0.0);
    $activity = $this->activitySummary($allCourseNodes);

    return [
      'id' => $schoolEnrollment?->uuid ?? 'school-'.$school->uuid,
      'status' => $schoolEnrollment ? $this->enumValue($schoolEnrollment->status) : 'active',
      'progress_percent' => $progress,
      'enrolled_at' => $schoolEnrollment?->enrolled_at?->toIso8601String(),
      'last_activity_at' => $activity['last_activity_at'],
      'current_course' => $activity['current_course'],
      'school' => [
        'id' => $school->uuid,
        'slug' => $school->slug,
        'title' => $school->title,
        'thumbnail_url' => $school->relationLoaded('thumbnailMedia')
          ? $school->thumbnailMedia?->url()
          : null,
      ],
      'courses_count' => $total,
      'courses_completed' => $completed,
      'courses_in_progress' => $inProgress,
      'courses_remaining' => $notStarted,
      'modules_count' => $moduleNodes->count(),
      'modules_completed' => $modulesCompleted,
      'modules_remaining' => max(0, $moduleNodes->count() - $modulesCompleted),
      'modules' => $moduleNodes->all(),
      'unassigned_courses' => $unassigned,
    ];
  }

  /**
   * @param  Collection<int, Enrollment>  $enrollments
   * @param  Collection<int, Enrollment>  $enrollmentsByCourseId
   * @return list<array<string, mixed>>
   */
  private function freeCategoryNodes(Collection $enrollments, Collection $enrollmentsByCourseId): array
  {
    $freeEnrollments = $enrollments->filter(function (Enrollment $enrollment): bool {
      $course = $enrollment->course;

      return $course !== null && $course->school_id === null;
    });

    if ($freeEnrollments->isEmpty()) {
      return [];
    }

    $categoryIds = $freeEnrollments
      ->map(fn (Enrollment $enrollment) => $enrollment->course?->category_id)
      ->filter()
      ->unique()
      ->all();

    $categories = CourseCategory::query()->whereIn('id', $categoryIds)->get();

    return $categories->map(function (CourseCategory $category) use ($enrollmentsByCourseId) {
      $programModules = LmsProgramModule::query()
        ->where('container_type', ProgramModuleContainerType::Category)
        ->where('category_id', $category->id)
        ->where('status', ModuleStatus::Published)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

      $courses = Course::query()
        ->where('category_id', $category->id)
        ->whereNull('school_id')
        ->where('status', CourseStatus::Published)
        ->whereIn('id', $enrollmentsByCourseId->keys()->all() ?: [0])
        ->with($this->courseCurriculumRelations())
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

      $moduleNodes = $programModules->map(function (LmsProgramModule $module) use ($courses, $enrollmentsByCourseId) {
        $moduleCourses = $courses
          ->where('program_module_id', $module->id)
          ->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
          ])
          ->values()
          ->map(fn (Course $course) => $this->courseNode($course, $enrollmentsByCourseId->get($course->id)))
          ->all();

        return $this->moduleNode($module, $moduleCourses);
      })->values();

      $assignedIds = $programModules->pluck('id')->all();
      $unassigned = $courses
        ->filter(fn (Course $course) => $course->program_module_id === null || ! in_array($course->program_module_id, $assignedIds, true))
        ->values()
        ->map(fn (Course $course) => $this->courseNode($course, $enrollmentsByCourseId->get($course->id)))
        ->all();

      $allCourses = collect($moduleNodes->flatMap(fn (array $module) => $module['courses'] ?? [])->all())
        ->concat($unassigned);
      $completed = $allCourses->where('status', 'completed')->count();
      $activity = $this->activitySummary($allCourses);

      return [
        'id' => $category->uuid,
        'category' => [
          'id' => $category->uuid,
          'slug' => $category->slug,
          'name' => $category->name,
        ],
        'courses_count' => $allCourses->count(),
        'courses_completed' => $completed,
        'courses_remaining' => $allCourses->count() - $completed,
        'modules_count' => $moduleNodes->count(),
        'last_activity_at' => $activity['last_activity_at'],
        'current_course' => $activity['current_course'],
        'modules' => $moduleNodes->all(),
        'unassigned_courses' => $unassigned,
      ];
    })->values()->all();
  }

  /**
   * @param  list<array<string, mixed>>  $courses
   * @return array<string, mixed>
   */
  private function moduleNode(LmsProgramModule $module, array $courses): array
  {
    $collection = collect($courses);
    $total = $collection->count();
    $completed = $collection->where('status', 'completed')->count();
    $inProgress = $collection->where('status', 'in_progress')->count();
    $notStarted = $collection->where('status', 'not_started')->count();

    return [
      'id' => $module->uuid,
      'number' => (int) $module->sort_order,
      'title' => $module->title,
      'description' => $module->description,
      'sort_order' => (int) $module->sort_order,
      'courses_count' => $total,
      'courses_completed' => $completed,
      'courses_in_progress' => $inProgress,
      'courses_not_started' => $notStarted,
      'progress_percent' => $total > 0 ? round((float) $collection->avg('progress_percent'), 1) : 0.0,
      'courses' => $courses,
    ];
  }

  /**
   * @param  Collection<int, array<string, mixed>>  $courseNodes
   * @return array{last_activity_at: string|null, current_course: array<string, mixed>|null}
   */
  private function activitySummary(Collection $courseNodes): array
  {
    $withActivity = $courseNodes
      ->filter(fn (array $course) => filled($course['last_accessed_at'] ?? null))
      ->sortByDesc('last_accessed_at')
      ->values();
    $current = $withActivity->first()
      ?? $courseNodes->firstWhere('status', 'in_progress')
      ?? $courseNodes->first();

    return [
      'last_activity_at' => $withActivity->first()['last_accessed_at'] ?? null,
      'current_course' => is_array($current) ? [
        'id' => $current['id'] ?? null,
        'title' => $current['title'] ?? null,
        'status' => $current['status'] ?? null,
        'progress_percent' => $current['progress_percent'] ?? 0,
        'enrollment_id' => $current['enrollment_id'] ?? null,
        'first_lesson_id' => $current['first_lesson_id'] ?? null,
        'current_lesson' => $current['current_lesson'] ?? null,
      ] : null,
    ];
  }

  /** @return array<string, mixed> */
  public function courseNode(?Course $course, ?Enrollment $enrollment, bool $isCurrent = false): array
  {
    if ($course === null) {
      return [];
    }

    $lessons = $course->relationLoaded('modules')
      ? $course->modules->flatMap(fn ($module) => $module->relationLoaded('lessons') ? $module->lessons : collect())
      : collect();
    $lessonsTotal = $lessons->count();
    $assessmentsTotal = $course->relationLoaded('modules')
      ? $course->modules->sum(fn ($module) => $module->relationLoaded('assessments') ? $module->assessments->count() : 0)
      : 0;
    $firstLesson = $lessons->first();

    $status = 'not_started';
    $progress = 0.0;
    $lastAccessed = null;
    $enrollmentId = null;
    $currentLesson = null;
    $lessonsCompleted = 0;
    $resumeLesson = $firstLesson;

    if ($enrollment !== null) {
      $enrollmentId = $enrollment->uuid;
      $progress = (float) $enrollment->progress_percent;
      $enrollmentStatus = $this->enumValue($enrollment->status);
      $progressRows = $enrollment->relationLoaded('lessonProgress')
        ? $enrollment->lessonProgress
        : collect();

      $lessonsCompleted = $progressRows
        ->filter(fn (LessonProgress $row) => $this->enumValue($row->status) === 'completed')
        ->count();

      if ($enrollmentStatus === 'completed' || $progress >= 100) {
        $status = 'completed';
      } elseif (
        $progress > 0
        || $progressRows->contains(fn (LessonProgress $row) => in_array($this->enumValue($row->status), ['in_progress', 'completed'], true))
      ) {
        $status = 'in_progress';
      }

      $lastAccessed = $enrollment->last_accessed_at?->toIso8601String()
        ?? $progressRows->sortByDesc('updated_at')->first()?->updated_at?->toIso8601String();

      $latest = $progressRows->sortByDesc('updated_at')->first();
      if ($latest?->lesson) {
        $currentLesson = [
          'id' => $latest->lesson->uuid,
          'title' => $latest->lesson->title,
        ];
      }

      $completedIds = $progressRows
        ->filter(fn (LessonProgress $row) => $this->enumValue($row->status) === 'completed')
        ->pluck('lesson_id');
      $resumeLesson = $lessons->first(fn (Lesson $lesson) => ! $completedIds->contains($lesson->id)) ?? $firstLesson;
    }

    if ($lessonsTotal === 0) {
      $lessonsTotal = (int) $course->lessons()->where('status', 'published')->count();
    }

    $programModule = $course->programModule;

    return [
      'id' => $course->uuid,
      'title' => $course->title,
      'slug' => $course->slug,
      'description' => $course->summary ?: $course->description,
      'sort_order' => (int) $course->sort_order,
      'enrollment_id' => $enrollmentId,
      'status' => $status,
      'progress_percent' => $progress,
      'last_accessed_at' => $lastAccessed,
      'access_state' => 'available',
      'is_current' => $isCurrent,
      'school' => $course->school ? [
        'id' => $course->school->uuid,
        'title' => $course->school->title,
        'slug' => $course->school->slug,
      ] : null,
      'program_module' => $this->programModuleRef($programModule),
      'course' => [
        'id' => $course->uuid,
        'title' => $course->title,
        'slug' => $course->slug,
        'cover_url' => $course->relationLoaded('coverMedia') && $course->coverMedia
          ? $course->coverMedia->url()
          : null,
      ],
      'current_lesson' => $currentLesson,
      'first_lesson_id' => $resumeLesson?->uuid ?? $firstLesson?->uuid,
      'lessons_total' => $lessonsTotal,
      'lessons_completed' => $lessonsCompleted,
      'assessments_total' => $assessmentsTotal,
    ];
  }

  /** @return array<string, \Closure> */
  private function courseCurriculumRelations(): array
  {
    return [
      'school',
      'programModule',
      'coverMedia',
      'modules' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order'),
      'modules.lessons' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order'),
      'modules.assessments' => fn ($query) => $query->where('status', 'published')->whereNull('lesson_id'),
    ];
  }
}
