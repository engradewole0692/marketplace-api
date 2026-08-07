<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\CourseCategory */
final class CourseCategoryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'description' => $this->description,
      'seo_title' => $this->seo_title,
      'seo_description' => $this->seo_description,
      'is_visible' => (bool) ($this->is_visible ?? true),
      'sort_order' => (int) $this->sort_order,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'icon' => $this->icon,
      'color' => is_string($this->icon) && str_starts_with($this->icon, 'color:')
        ? substr($this->icon, 6)
        : null,
      'cover_media_id' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->uuid),
      'cover_url' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->url()),
      'parent_id' => $this->relationLoaded('parent')
        ? $this->parent?->uuid
        : ($this->parent_id
          ? \App\Modules\Lms\Models\CourseCategory::query()->whereKey($this->parent_id)->value('uuid')
          : null),
      'courses_count' => $this->when(isset($this->courses_count), fn () => (int) $this->courses_count),
    ];
  }
}
