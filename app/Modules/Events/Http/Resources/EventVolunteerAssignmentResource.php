<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventVolunteerAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventVolunteerAssignment */
final class EventVolunteerAssignmentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'registration_id' => $this->registration?->uuid,
      'member_id' => $this->member?->uuid,
      'role' => $this->whenLoaded('role', fn () => [
        'id' => $this->role?->uuid,
        'name' => $this->role?->name,
      ]),
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'shift_starts_at' => $this->shift_starts_at?->toIso8601String(),
      'shift_ends_at' => $this->shift_ends_at?->toIso8601String(),
      'notes' => $this->notes,
      'performance_score' => $this->performance_score,
      'completed_at' => $this->completed_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
