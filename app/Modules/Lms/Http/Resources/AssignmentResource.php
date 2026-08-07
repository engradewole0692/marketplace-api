<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\Assignment */
final class AssignmentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'slug' => $this->slug,
      'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
      'instructions' => $this->instructions,
      'objective' => $this->objective,
      'rubric' => $this->rubric,
      'max_score' => (int) $this->max_score,
      'pass_mark' => (float) $this->pass_mark,
      'max_attempts' => (int) $this->max_attempts,
      'allow_resubmission' => (bool) $this->allow_resubmission,
      'allow_attachments' => (bool) $this->allow_attachments,
      'max_attachments' => (int) $this->max_attachments,
      'due_at' => $this->due_at?->toIso8601String(),
      'is_required' => (bool) $this->is_required,
      'status' => $this->status,
      'sort_order' => (int) $this->sort_order,
      'submissions_count' => $this->when(isset($this->submissions_count), fn () => (int) $this->submissions_count),
      'course' => $this->whenLoaded('course', fn () => $this->course ? [
        'id' => $this->course->uuid,
        'title' => $this->course->title,
      ] : null),
      'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson ? [
        'id' => $this->lesson->uuid,
        'title' => $this->lesson->title,
      ] : null),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
