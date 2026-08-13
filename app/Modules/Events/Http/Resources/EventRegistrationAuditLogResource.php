<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationAuditLog */
final class EventRegistrationAuditLogResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => (string) $this->id,
      'event_type' => $this->event_type instanceof \BackedEnum ? $this->event_type->value : $this->event_type,
      'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
      'old_values' => $this->old_values,
      'new_values' => $this->new_values,
      'metadata' => $this->metadata,
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
