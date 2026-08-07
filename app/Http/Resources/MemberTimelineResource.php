<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberTimeline
 */
final class MemberTimelineResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'event_type' => $this->event_type instanceof \BackedEnum ? $this->event_type->value : $this->event_type,
      'description' => $this->description,
      'metadata' => $this->metadata,
      'occurred_at' => $this->occurred_at?->toIso8601String(),
      'actor' => new UserResource($this->whenLoaded('actor')),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
