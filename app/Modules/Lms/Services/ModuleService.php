<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ModuleService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $data
   */
  public function create(Course $course, array $data, User $actor): CourseModule
  {
    $sort = (int) ($data['sort_order'] ?? (($course->modules()->max('sort_order') ?? 0) + 1));

    return CourseModule::query()->create([
      'course_id' => $course->id,
      'title' => $data['title'],
      'slug' => $this->uniqueSlug($course->id, $data['slug'] ?? Str::slug($data['title'])),
      'description' => $data['description'] ?? null,
      'sort_order' => $sort,
      'status' => $data['status'] ?? ModuleStatus::Draft->value,
      'is_preview' => (bool) ($data['is_preview'] ?? false),
      'duration_minutes' => $data['duration_minutes'] ?? null,
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(CourseModule $module, array $data, User $actor): CourseModule
  {
    $payload = collect($data)->only([
      'title', 'description', 'sort_order', 'status', 'is_preview', 'duration_minutes',
    ])->all();
    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueSlug($module->course_id, $data['slug'], $module->id);
    }
    $payload['updated_by_user_id'] = $actor->id;
    $module->fill($payload)->save();

    return $module->fresh(['lessons']);
  }

  /**
   * @param  list<array{id: string, sort_order: int}>  $items
   */
  public function reorder(Course $course, array $items): void
  {
    DB::transaction(function () use ($course, $items): void {
      foreach ($items as $item) {
        CourseModule::query()
          ->where('course_id', $course->id)
          ->where('uuid', $item['id'])
          ->update(['sort_order' => (int) $item['sort_order']]);
      }
    });
  }

  public function delete(CourseModule $module): void
  {
    $module->delete();
  }

  public function duplicate(CourseModule $module, User $actor): CourseModule
  {
    return DB::transaction(function () use ($module, $actor): CourseModule {
      $module->load('lessons.resources');
      $copy = $module->replicate(['uuid']);
      $copy->title = $module->title.' (Copy)';
      $copy->slug = $this->uniqueSlug($module->course_id, Str::slug($copy->title));
      $copy->sort_order = ((int) ($module->course?->modules()->max('sort_order') ?? $module->sort_order)) + 1;
      $copy->created_by_user_id = $actor->id;
      $copy->updated_by_user_id = $actor->id;
      $copy->save();

      foreach ($module->lessons as $lesson) {
        $lessonCopy = $lesson->replicate(['uuid']);
        $lessonCopy->module_id = $copy->id;
        $lessonCopy->course_id = $copy->course_id;
        $lessonCopy->slug = $lesson->slug.'-copy';
        $lessonCopy->created_by_user_id = $actor->id;
        $lessonCopy->updated_by_user_id = $actor->id;
        $lessonCopy->save();

        foreach ($lesson->resources as $resource) {
          $resourceCopy = $resource->replicate(['uuid']);
          $resourceCopy->lesson_id = $lessonCopy->id;
          $resourceCopy->save();
        }
      }

      return $copy->fresh(['lessons.resources']);
    });
  }

  private function uniqueSlug(int $courseId, string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'module';
    $candidate = $base;
    $i = 1;
    while (
      CourseModule::query()
        ->where('course_id', $courseId)
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }
}
