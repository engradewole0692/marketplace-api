<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Lms\Models\Enrollment */
final class EnrollmentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'learner_type' => $this->learner_type instanceof \BackedEnum ? $this->learner_type->value : $this->learner_type,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'enrolled_at' => $this->enrolled_at?->toIso8601String(),
      'completed_at' => $this->completed_at?->toIso8601String(),
      'progress_percent' => (float) $this->progress_percent,
      'price_paid' => $this->price_paid !== null ? (float) $this->price_paid : null,
      'currency' => $this->currency,
      'coupon_code' => $this->coupon_code,
      'course' => $this->whenLoaded('course', fn () => new CourseResource($this->course)),
      'user' => $this->whenLoaded('user', fn () => [
        'id' => $this->user->uuid,
        'name' => $this->user->name,
        'email' => $this->user->email,
      ]),
      'certificate' => $this->whenLoaded('certificate', fn () => $this->certificate ? [
        'id' => $this->certificate->uuid,
        'certificate_number' => $this->certificate->certificate_number,
        'verification_code' => $this->certificate->verification_code,
        'status' => $this->certificate->status instanceof \BackedEnum
          ? $this->certificate->status->value
          : $this->certificate->status,
        'issued_at' => $this->certificate->issued_at?->toIso8601String(),
      ] : null),
    ];
  }
}
