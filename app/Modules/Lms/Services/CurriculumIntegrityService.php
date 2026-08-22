<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\ProgramModuleContainerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use Illuminate\Support\Collection;

/**
 * Reports curriculum-data issues. Never auto-repairs production mappings.
 */
final class CurriculumIntegrityService implements ServiceContract
{
  /**
   * @return array<string, mixed>
   */
  public function forSchool(LmsSchool $school): array
  {
    $modules = LmsProgramModule::query()
      ->where('container_type', ProgramModuleContainerType::School)
      ->where('school_id', $school->id)
      ->orderBy('sort_order')
      ->orderBy('id')
      ->with(['courses' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
      ->get();

    $courses = Course::query()
      ->where('school_id', $school->id)
      ->orderBy('sort_order')
      ->get();

    return $this->report($modules, $courses, 'school');
  }

  /**
   * @return array<string, mixed>
   */
  public function forCategory(CourseCategory $category): array
  {
    $modules = LmsProgramModule::query()
      ->where('container_type', ProgramModuleContainerType::Category)
      ->where('category_id', $category->id)
      ->orderBy('sort_order')
      ->orderBy('id')
      ->with(['courses' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
      ->get();

    $courses = Course::query()
      ->where('category_id', $category->id)
      ->whereNull('school_id')
      ->orderBy('sort_order')
      ->get();

    return $this->report($modules, $courses, 'category');
  }

  /**
   * @param  Collection<int, LmsProgramModule>  $modules
   * @param  Collection<int, Course>  $courses
   * @return array<string, mixed>
   */
  private function report(Collection $modules, Collection $courses, string $container): array
  {
    $moduleIds = $modules->pluck('id')->all();
    $issues = [];

    $byOrder = $modules->groupBy(fn (LmsProgramModule $module) => (int) $module->sort_order);
    foreach ($byOrder as $order => $group) {
      if ($group->count() < 2) {
        continue;
      }
      $issues[] = [
        'code' => 'duplicate_sort_order',
        'severity' => 'warning',
        'message' => 'Multiple programme modules share sort_order '.$order.'. The intended order was not guessed.',
        'sort_order' => (int) $order,
        'modules' => $group->map(fn (LmsProgramModule $module) => $this->moduleRef($module))->values()->all(),
      ];
    }

    $orders = $byOrder->keys()->map(fn ($order) => (int) $order)->sort()->values();
    $missing = [];
    if ($orders->count() > 0) {
      for ($n = (int) $orders->first(); $n <= (int) $orders->last(); $n++) {
        if (! $byOrder->has($n)) {
          $missing[] = $n;
        }
      }
    }
    if ($missing !== []) {
      $issues[] = [
        'code' => 'missing_sort_order',
        'severity' => 'warning',
        'message' => 'Programme-module sort_order sequence has gaps: '.implode(', ', $missing).'.',
        'sort_orders' => $missing,
      ];
    }

    foreach ($modules as $module) {
      if ($module->courses->isEmpty()) {
        $issues[] = [
          'code' => 'empty_programme_module',
          'severity' => 'warning',
          'message' => $module->title.' has no courses assigned.',
          'module' => $this->moduleRef($module),
        ];
      }

      $courseOrderGroups = $module->courses->groupBy(fn (Course $course) => (int) $course->sort_order);
      foreach ($courseOrderGroups as $order => $group) {
        if ($group->count() < 2) {
          continue;
        }
        $issues[] = [
          'code' => 'duplicate_course_ordering',
          'severity' => 'info',
          'message' => $module->title.' has multiple courses with sort_order '.$order.'.',
          'sort_order' => (int) $order,
          'module' => $this->moduleRef($module),
          'courses' => $group->map(fn (Course $course) => $this->courseRef($course))->values()->all(),
        ];
      }
    }

    $unassigned = $courses->filter(fn (Course $course) => $course->program_module_id === null);
    foreach ($unassigned as $course) {
      $issues[] = [
        'code' => 'course_without_program_module',
        'severity' => 'warning',
        'message' => $course->title.' has no program_module_id.',
        'course' => $this->courseRef($course),
      ];
    }

    $orphaned = $courses->filter(function (Course $course) use ($moduleIds): bool {
      return $course->program_module_id !== null && ! in_array($course->program_module_id, $moduleIds, true);
    });
    foreach ($orphaned as $course) {
      $issues[] = [
        'code' => 'orphaned_course',
        'severity' => 'warning',
        'message' => $course->title.' points at a programme module that does not belong to this '.$container.'.',
        'course' => $this->courseRef($course),
      ];
    }

    return [
      'container' => $container,
      'issue_count' => count($issues),
      'has_issues' => $issues !== [],
      'auto_repaired' => false,
      'issues' => $issues,
    ];
  }

  /** @return array{id: string, title: string, sort_order: int, number: int, courses_count: int} */
  private function moduleRef(LmsProgramModule $module): array
  {
    return [
      'id' => $module->uuid,
      'title' => $module->title,
      'sort_order' => (int) $module->sort_order,
      'number' => (int) $module->sort_order,
      'courses_count' => $module->relationLoaded('courses') ? $module->courses->count() : 0,
    ];
  }

  /** @return array{id: string, title: string, course_code: string|null, sort_order: int, status: string} */
  private function courseRef(Course $course): array
  {
    $status = $course->status instanceof \BackedEnum ? $course->status->value : (string) $course->status;

    return [
      'id' => $course->uuid,
      'title' => $course->title,
      'course_code' => $course->course_code,
      'sort_order' => (int) $course->sort_order,
      'status' => $status,
    ];
  }
}
