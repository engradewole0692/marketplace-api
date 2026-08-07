<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventCoupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventCoupon */
final class EventCouponResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'code' => $this->code,
      'discount_type' => $this->discount_type instanceof \BackedEnum ? $this->discount_type->value : $this->discount_type,
      'discount_value' => (float) $this->discount_value,
      'max_uses' => $this->max_uses,
      'used_count' => (int) $this->used_count,
      'starts_at' => $this->starts_at?->toIso8601String(),
      'ends_at' => $this->ends_at?->toIso8601String(),
      'is_active' => (bool) $this->is_active,
    ];
  }
}
