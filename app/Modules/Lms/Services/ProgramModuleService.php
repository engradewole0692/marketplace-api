<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgramModuleContainerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProgramModuleService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $data
   */
  public function createForSchool(LmsSchool $school, array $data): LmsProgramModule
  {
    return LmsProgramModule::query()->create([
      'container_type' => ProgramModuleContainerType::School,
      'school_id' => $school->id,
      'category_id' => null,
      'title' => $data['title'],
      'slug' => $this->uniqueSlug(ProgramModuleContainerType::School, $school->id, null, $data['slug'] ?? Str::slug($data['title'])),
      'description' => $data['description'] ?? null,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'status' => $data['status'] ?? ModuleStatus::Published->value,
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createForCategory(CourseCategory $category, array $data): LmsProgramModule
  {
    return LmsProgramModule::query()->create([
      'container_type' => ProgramModuleContainerType::Category,
      'school_id' => null,
      'category_id' => $category->id,
      'title' => $data['title'],
      'slug' => $this->uniqueSlug(ProgramModuleContainerType::Category, null, $category->id, $data['slug'] ?? Str::slug($data['title'])),
      'description' => $data['description'] ?? null,
      'sort_order' => (int) ($data['sort_order'] ?? 0),
      'status' => $data['status'] ?? ModuleStatus::Published->value,
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(LmsProgramModule $module, array $data): LmsProgramModule
  {
    if (isset($data['title']) && ! isset($data['slug'])) {
      $data['slug'] = Str::slug($data['title']);
    }

    if (isset($data['slug'])) {
      $data['slug'] = $this->uniqueSlug(
        $module->container_type instanceof ProgramModuleContainerType
          ? $module->container_type
          : ProgramModuleContainerType::from((string) $module->container_type),
        $module->school_id,
        $module->category_id,
        (string) $data['slug'],
        $module->id,
      );
    }

    $module->fill(collect($data)->only([
      'title', 'slug', 'description', 'sort_order', 'status', 'metadata',
    ])->all())->save();

    return $module->fresh(['courses']);
  }

  public function assignCourse(LmsProgramModule $module, Course $course): Course
  {
    if ($module->container_type === ProgramModuleContainerType::School) {
      if ($course->school_id !== $module->school_id) {
        throw new BusinessException(
          'Course must belong to the same school as this programme module.',
          ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }
    } elseif ($module->container_type === ProgramModuleContainerType::Category) {
      if ($course->school_id !== null) {
        throw new BusinessException(
          'School courses cannot be assigned to a free category module.',
          ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }
      if ($course->category_id !== $module->category_id) {
        throw new BusinessException(
          'Course must belong to the same free category as this programme module.',
          ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }
    }

    $course->forceFill(['program_module_id' => $module->id])->save();

    return $course->fresh(['programModule', 'school', 'category']);
  }

  public function unassignCourse(Course $course): Course
  {
    $course->forceFill(['program_module_id' => null])->save();

    return $course->fresh();
  }

  /** @return Collection<int, LmsProgramModule> */
  public function forSchool(LmsSchool $school, bool $publishedOnly = true): Collection
  {
    $query = LmsProgramModule::query()
      ->where('container_type', ProgramModuleContainerType::School)
      ->where('school_id', $school->id)
      ->orderBy('sort_order')
      ->with(['courses' => fn ($q) => $q->orderBy('sort_order')]);

    if ($publishedOnly) {
      $query->where('status', ModuleStatus::Published);
    }

    return $query->get();
  }

  /** @return Collection<int, LmsProgramModule> */
  public function forCategory(CourseCategory $category, bool $publishedOnly = true): Collection
  {
    $query = LmsProgramModule::query()
      ->where('container_type', ProgramModuleContainerType::Category)
      ->where('category_id', $category->id)
      ->orderBy('sort_order')
      ->with(['courses' => fn ($q) => $q->orderBy('sort_order')]);

    if ($publishedOnly) {
      $query->where('status', ModuleStatus::Published);
    }

    return $query->get();
  }

  public function ensureDefaultForSchool(LmsSchool $school): LmsProgramModule
  {
    $existing = LmsProgramModule::query()
      ->where('school_id', $school->id)
      ->orderBy('sort_order')
      ->first();

    if ($existing) {
      return $existing;
    }

    return $this->createForSchool($school, [
      'title' => 'Module 1',
      'slug' => 'module-1',
      'sort_order' => 1,
    ]);
  }

  /** @param  list<string>  $orderedModuleUuids */
  public function reorderSchoolModules(LmsSchool $school, array $orderedModuleUuids): void
  {
    foreach ($orderedModuleUuids as $index => $uuid) {
      LmsProgramModule::query()
        ->where('uuid', $uuid)
        ->where('school_id', $school->id)
        ->update(['sort_order' => $index + 1]);
    }
  }

  /** @param  list<string>  $orderedModuleUuids */
  public function reorderCategoryModules(CourseCategory $category, array $orderedModuleUuids): void
  {
    foreach ($orderedModuleUuids as $index => $uuid) {
      LmsProgramModule::query()
        ->where('uuid', $uuid)
        ->where('category_id', $category->id)
        ->update(['sort_order' => $index + 1]);
    }
  }

  /** @param  list<string>  $orderedCourseUuids */
  public function reorderModuleCourses(LmsProgramModule $module, array $orderedCourseUuids): void
  {
    foreach ($orderedCourseUuids as $index => $uuid) {
      Course::query()
        ->where('uuid', $uuid)
        ->where('program_module_id', $module->id)
        ->update(['sort_order' => $index + 1]);
    }
  }

  public function delete(LmsProgramModule $module): void
  {
    Course::query()->where('program_module_id', $module->id)->update(['program_module_id' => null]);
    $module->delete();
  }

  private function uniqueSlug(
    ProgramModuleContainerType $type,
    ?int $schoolId,
    ?int $categoryId,
    string $base,
    ?int $ignoreId = null,
  ): string {
    $slug = Str::slug($base) ?: 'module';
    $candidate = $slug;
    $i = 2;

    while ($this->slugExists($type, $schoolId, $categoryId, $candidate, $ignoreId)) {
      $candidate = $slug.'-'.$i;
      $i++;
    }

    return $candidate;
  }

  private function slugExists(
    ProgramModuleContainerType $type,
    ?int $schoolId,
    ?int $categoryId,
    string $slug,
    ?int $ignoreId,
  ): bool {
    $query = LmsProgramModule::query()->where('container_type', $type)->where('slug', $slug);

    if ($type === ProgramModuleContainerType::School) {
      $query->where('school_id', $schoolId);
    } else {
      $query->where('category_id', $categoryId);
    }

    if ($ignoreId) {
      $query->where('id', '!=', $ignoreId);
    }

    return $query->exists();
  }
}
