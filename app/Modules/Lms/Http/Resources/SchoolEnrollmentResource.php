<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\SchoolEnrollment */
final class SchoolEnrollmentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'learner_type' => $this->learner_type instanceof \BackedEnum ? $this->learner_type->value : $this->learner_type,
      'price_paid' => $this->price_paid !== null ? (float) $this->price_paid : null,
      'currency' => $this->currency,
      'payment_reference' => $this->payment_reference,
      'progress_percent' => $this->progress_percent !== null ? (float) $this->progress_percent : 0,
      'enrolled_at' => $this->enrolled_at?->toIso8601String(),
      'completed_at' => $this->completed_at?->toIso8601String(),
      'cancelled_at' => $this->cancelled_at?->toIso8601String(),
      'school' => $this->whenLoaded('school', fn () => new SchoolResource($this->school)),
      'user' => $this->whenLoaded('user', fn () => [
        'id' => $this->user?->uuid,
        'name' => $this->user?->name,
        'email' => $this->user?->email,
      ]),
    ];
  }
}
