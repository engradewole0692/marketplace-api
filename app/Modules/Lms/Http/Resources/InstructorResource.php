<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\Instructor */
final class InstructorResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'title' => $this->title,
      'bio' => $this->bio,
      'email' => $this->email,
      'website_url' => $this->website_url,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'photo_media_id' => $this->whenLoaded('photoMedia', fn () => $this->photoMedia?->uuid),
      'photo_url' => $this->whenLoaded('photoMedia', fn () => $this->photoMedia?->url()),
      'metadata' => $this->metadata,
      'phone' => is_array($this->metadata) ? ($this->metadata['phone'] ?? null) : null,
      'ministry' => is_array($this->metadata) ? ($this->metadata['ministry'] ?? null) : null,
      'social_links' => is_array($this->metadata) ? ($this->metadata['social_links'] ?? null) : null,
      'experience' => is_array($this->metadata) ? ($this->metadata['experience'] ?? null) : null,
      'is_primary' => $this->whenPivotLoaded('lms_course_instructor', fn () => (bool) $this->pivot->is_primary),
      'role_label' => $this->whenPivotLoaded('lms_course_instructor', fn () => $this->pivot->role_label),
      'courses_count' => $this->when(isset($this->courses_count), fn () => (int) $this->courses_count),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
