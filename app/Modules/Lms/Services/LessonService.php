<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LessonService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $data
   */
  public function create(CourseModule $module, array $data, User $actor): Lesson
  {
    $sort = (int) ($data['sort_order'] ?? (($module->lessons()->max('sort_order') ?? 0) + 1));

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $module->course_id,
      'title' => $data['title'],
      'slug' => $this->uniqueSlug($module->id, $data['slug'] ?? Str::slug($data['title'])),
      'summary' => $data['summary'] ?? null,
      'content' => $data['content'] ?? null,
      'sort_order' => $sort,
      'status' => $data['status'] ?? ModuleStatus::Draft->value,
      'lesson_type' => $data['lesson_type'] ?? LessonType::Video->value,
      'is_preview' => (bool) ($data['is_preview'] ?? false),
      'duration_minutes' => $data['duration_minutes'] ?? null,
      'video_source' => $data['video_source'] ?? VideoSource::None->value,
      'youtube_video_id' => $data['youtube_video_id'] ?? $this->extractYoutubeId($data['youtube_url'] ?? null),
      'youtube_url' => $data['youtube_url'] ?? null,
      'video_media_id' => ! empty($data['video_media_id'])
        ? CmsMedia::query()->where('uuid', $data['video_media_id'])->value('id')
        : null,
      'embed_html' => $data['embed_html'] ?? null,
      'is_mandatory' => (bool) ($data['is_mandatory'] ?? true),
      'completion_threshold_percent' => (int) ($data['completion_threshold_percent'] ?? 100),
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);

    if (! empty($data['resources']) && is_array($data['resources'])) {
      $this->syncResources($lesson, $data['resources']);
    }

    return $lesson->fresh(['resources', 'videoMedia']);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Lesson $lesson, array $data, User $actor): Lesson
  {
    $payload = collect($data)->only([
      'title', 'summary', 'content', 'sort_order', 'status', 'lesson_type',
      'is_preview', 'duration_minutes', 'video_source', 'youtube_url', 'embed_html',
      'is_mandatory', 'completion_threshold_percent',
    ])->all();

    if (isset($data['slug'])) {
      $payload['slug'] = $this->uniqueSlug($lesson->module_id, $data['slug'], $lesson->id);
    }
    if (array_key_exists('youtube_url', $data) || array_key_exists('youtube_video_id', $data)) {
      $payload['youtube_video_id'] = $data['youtube_video_id'] ?? $this->extractYoutubeId($data['youtube_url'] ?? $lesson->youtube_url);
    }
    if (array_key_exists('video_media_id', $data)) {
      $payload['video_media_id'] = $data['video_media_id']
        ? CmsMedia::query()->where('uuid', $data['video_media_id'])->value('id')
        : null;
    }
    $payload['updated_by_user_id'] = $actor->id;
    $lesson->fill($payload)->save();

    if (isset($data['resources']) && is_array($data['resources'])) {
      $this->syncResources($lesson, $data['resources']);
    }

    return $lesson->fresh(['resources', 'videoMedia']);
  }

  /**
   * @param  list<array{id: string, sort_order: int}>  $items
   */
  public function reorder(CourseModule $module, array $items): void
  {
    DB::transaction(function () use ($module, $items): void {
      foreach ($items as $item) {
        Lesson::query()
          ->where('module_id', $module->id)
          ->where('uuid', $item['id'])
          ->update(['sort_order' => (int) $item['sort_order']]);
      }
    });
  }

  public function delete(Lesson $lesson): void
  {
    $lesson->delete();
  }

  public function duplicate(Lesson $lesson, User $actor): Lesson
  {
    return DB::transaction(function () use ($lesson, $actor): Lesson {
      $lesson->load('resources');
      $copy = $lesson->replicate(['uuid']);
      $copy->title = $lesson->title.' (Copy)';
      $copy->slug = $this->uniqueSlug($lesson->module_id, Str::slug($copy->title));
      $copy->sort_order = ((int) ($lesson->module?->lessons()->max('sort_order') ?? $lesson->sort_order)) + 1;
      $copy->created_by_user_id = $actor->id;
      $copy->updated_by_user_id = $actor->id;
      $copy->save();

      foreach ($lesson->resources as $resource) {
        $resourceCopy = $resource->replicate(['uuid']);
        $resourceCopy->lesson_id = $copy->id;
        $resourceCopy->save();
      }

      return $copy->fresh(['resources', 'videoMedia']);
    });
  }

  /**
   * @param  list<array<string, mixed>>  $resources
   */
  private function syncResources(Lesson $lesson, array $resources): void
  {
    $keep = [];
    foreach ($resources as $index => $row) {
      $attrs = [
        'title' => $row['title'],
        'resource_type' => $row['resource_type'] ?? 'pdf',
        'external_url' => $row['external_url'] ?? null,
        'sort_order' => (int) ($row['sort_order'] ?? $index),
        'is_downloadable' => (bool) ($row['is_downloadable'] ?? true),
        'access_level' => $row['access_level'] ?? 'free',
        'is_preview_only' => (bool) ($row['is_preview_only'] ?? false),
        'file_media_id' => ! empty($row['file_media_id'])
          ? CmsMedia::query()->where('uuid', $row['file_media_id'])->value('id')
          : null,
      ];

      if (! empty($row['id'])) {
        $existing = LessonResource::query()
          ->where('lesson_id', $lesson->id)
          ->where('uuid', $row['id'])
          ->first();
        if ($existing) {
          $existing->fill($attrs)->save();
          $keep[] = $existing->id;
          continue;
        }
      }

      $created = LessonResource::query()->create([...$attrs, 'lesson_id' => $lesson->id]);
      $keep[] = $created->id;
    }

    LessonResource::query()
      ->where('lesson_id', $lesson->id)
      ->whereNotIn('id', $keep)
      ->delete();
  }

  private function uniqueSlug(int $moduleId, string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'lesson';
    $candidate = $base;
    $i = 1;
    while (
      Lesson::query()
        ->where('module_id', $moduleId)
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }

  public function extractYoutubeId(?string $url): ?string
  {
    return app(YoutubeMetadataService::class)->extractVideoId($url);
  }
}
