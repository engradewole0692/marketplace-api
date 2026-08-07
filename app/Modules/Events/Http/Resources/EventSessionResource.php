<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventSession */
final class EventSessionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'speaker' => $this->whenLoaded('speaker', fn () => new SpeakerResource($this->speaker)),
      'title' => $this->title,
      'session_type' => $this->session_type,
      'description' => $this->description,
      'starts_at' => $this->starts_at?->toIso8601String(),
      'ends_at' => $this->ends_at?->toIso8601String(),
      'location' => $this->location,
      'capacity' => $this->capacity,
      'sort_order' => $this->sort_order,
      'track' => $this->track,
      'room' => $this->room,
      'moderator_user_id' => $this->moderator_user_id,
      'resources' => $this->resources_json,
    ];
  }
}
