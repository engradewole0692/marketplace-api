<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationTimeline */
final class EventRegistrationTimelineResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_type' => $this->event_type instanceof \BackedEnum ? $this->event_type->value : $this->event_type,
      'description' => $this->description,
      'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
      'metadata' => $this->metadata,
      'occurred_at' => $this->occurred_at?->toIso8601String(),
    ];
  }
}
