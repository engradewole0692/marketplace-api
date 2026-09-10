<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventStaffAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventStaffAssignment */
final class EventStaffAssignmentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $user = $this->user;

    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'staff_role' => $this->staff_role,
      'is_active' => (bool) $this->is_active,
      'user' => $user === null ? null : [
        'id' => $user->uuid,
        'name' => $user->name,
        'email' => $user->email,
      ],
      'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : [
        'id' => $this->createdBy->uuid,
        'name' => $this->createdBy->name,
      ]),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
