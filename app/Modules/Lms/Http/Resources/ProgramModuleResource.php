<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\LmsProgramModule */
final class ProgramModuleResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'container_type' => $this->container_type instanceof \BackedEnum
        ? $this->container_type->value
        : $this->container_type,
      'title' => $this->title,
      'slug' => $this->slug,
      'description' => $this->description,
      'sort_order' => (int) ($this->sort_order ?? 0),
      'number' => (int) ($this->sort_order ?? 0),
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'courses' => CourseResource::collection($this->whenLoaded('courses')),
      'courses_count' => $this->whenCounted('courses'),
    ];
  }
}
