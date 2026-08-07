<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\Lesson */
final class LessonResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'slug' => $this->slug,
      'summary' => $this->summary,
      'content' => $this->content,
      'sort_order' => (int) $this->sort_order,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'lesson_type' => $this->lesson_type instanceof \BackedEnum ? $this->lesson_type->value : $this->lesson_type,
      'is_preview' => (bool) $this->is_preview,
      'duration_minutes' => $this->duration_minutes,
      'video_source' => $this->video_source instanceof \BackedEnum ? $this->video_source->value : $this->video_source,
      'youtube_video_id' => $this->youtube_video_id,
      'youtube_url' => $this->youtube_url,
      'video_media_id' => $this->whenLoaded('videoMedia', fn () => $this->videoMedia?->uuid),
      'video_url' => $this->whenLoaded('videoMedia', fn () => $this->videoMedia?->url()),
      'embed_html' => $this->embed_html,
      'is_mandatory' => (bool) $this->is_mandatory,
      'completion_threshold_percent' => (int) $this->completion_threshold_percent,
      'resources' => $this->whenLoaded('resources', fn () => $this->resources->map(fn ($r) => [
        'id' => $r->uuid,
        'title' => $r->title,
        'resource_type' => $r->resource_type instanceof \BackedEnum ? $r->resource_type->value : $r->resource_type,
        'external_url' => $r->external_url,
        'file_media_id' => $r->relationLoaded('fileMedia') ? $r->fileMedia?->uuid : null,
        'is_downloadable' => (bool) $r->is_downloadable,
        'access_level' => $r->access_level instanceof \BackedEnum ? $r->access_level->value : ($r->access_level ?? 'free'),
        'is_preview_only' => (bool) ($r->is_preview_only ?? false),
        'sort_order' => (int) $r->sort_order,
      ])),
    ];
  }
}
