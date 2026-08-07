<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\AssignmentSubmission */
final class AssignmentSubmissionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'attempt_number' => (int) $this->attempt_number,
      'essay_body' => $this->essay_body,
      'objective_answers' => $this->objective_answers,
      'attachments' => $this->attachments,
      'score' => $this->score !== null ? (float) $this->score : null,
      'max_score' => $this->max_score !== null ? (float) $this->max_score : null,
      'teacher_comments' => $this->teacher_comments,
      'submitted_at' => $this->submitted_at?->toIso8601String(),
      'returned_at' => $this->returned_at?->toIso8601String(),
      'graded_at' => $this->graded_at?->toIso8601String(),
      'assignment' => $this->whenLoaded('assignment', fn () => $this->assignment ? [
        'id' => $this->assignment->uuid,
        'title' => $this->assignment->title,
        'pass_mark' => (float) $this->assignment->pass_mark,
        'type' => $this->assignment->type instanceof \BackedEnum
          ? $this->assignment->type->value
          : $this->assignment->type,
      ] : null),
      'course' => $this->whenLoaded('assignment', fn () => $this->assignment?->relationLoaded('course') && $this->assignment->course
        ? [
          'id' => $this->assignment->course->uuid,
          'title' => $this->assignment->course->title,
        ]
        : null),
      'user' => $this->whenLoaded('user', fn () => $this->user ? [
        'id' => $this->user->uuid,
        'name' => $this->user->name,
        'email' => $this->user->email,
      ] : null),
    ];
  }
}
