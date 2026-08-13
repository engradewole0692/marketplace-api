<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationStatusTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationStatusTransition */
final class EventRegistrationStatusTransitionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'from_status' => $this->from_status,
      'to_status' => $this->to_status,
      'reason' => $this->reason,
      'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
