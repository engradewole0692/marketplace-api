<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventCheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventCheckIn */
final class EventCheckInResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'registration_id' => $this->registration?->uuid,
      'member_id' => $this->member?->uuid,
      'session_id' => $this->session?->uuid,
      'event_day_id' => $this->day?->uuid,
      'method' => $this->method instanceof \BackedEnum ? $this->method->value : $this->method,
      'checked_in_at' => $this->checked_in_at?->toIso8601String(),
      'operator' => $this->checkedInBy ? [
        'id' => $this->checkedInBy->uuid,
        'name' => $this->checkedInBy->name,
      ] : null,
      'notes' => $this->notes,
    ];
  }
}
