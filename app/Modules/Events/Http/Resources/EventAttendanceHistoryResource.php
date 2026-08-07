<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventAttendanceHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventAttendanceHistory */
final class EventAttendanceHistoryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'registration_id' => $this->registration?->uuid,
      'member_id' => $this->member?->uuid,
      'session_id' => $this->session?->uuid,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'source' => $this->source,
      'occurred_at' => $this->occurred_at?->toIso8601String(),
      'notes' => $this->notes,
    ];
  }
}
