<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\LmsSchool */
final class SchoolResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'slug' => $this->slug,
      'title' => $this->title,
      'subtitle' => $this->subtitle,
      'summary' => $this->summary,
      'description' => $this->description,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'sort_order' => (int) ($this->sort_order ?? 0),
      'member_price' => $this->member_price !== null ? (float) $this->member_price : null,
      'public_price' => $this->public_price !== null ? (float) $this->public_price : null,
      'currency' => $this->currency,
      'certificate_enabled' => (bool) ($this->certificate_enabled ?? true),
      'sequential_progression' => (bool) ($this->sequential_progression ?? true),
      'cover_media_id' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->uuid),
      'cover_url' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->url()),
      'thumbnail_media_id' => $this->whenLoaded('thumbnailMedia', fn () => $this->thumbnailMedia?->uuid),
      'thumbnail_url' => $this->whenLoaded('thumbnailMedia', fn () => $this->thumbnailMedia?->url()),
      'courses_count' => $this->whenCounted('courses'),
      'program_modules_count' => $this->whenCounted('programModules'),
      'published_at' => $this->published_at?->toIso8601String(),
      'metadata' => $this->metadata,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
      'courses' => CourseResource::collection($this->whenLoaded('courses')),
      'program_modules' => ProgramModuleResource::collection($this->whenLoaded('programModules')),
      'enrollment' => $this->when(
        isset($this->user_enrollment),
        fn () => $this->user_enrollment ? new SchoolEnrollmentResource($this->user_enrollment) : null,
      ),
    ];
  }
}
