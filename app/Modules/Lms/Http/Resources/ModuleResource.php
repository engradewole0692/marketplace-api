<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\CourseModule */
final class ModuleResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'slug' => $this->slug,
      'description' => $this->description,
      'sort_order' => (int) $this->sort_order,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'is_preview' => (bool) $this->is_preview,
      'duration_minutes' => $this->duration_minutes,
      'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
    ];
  }
}
