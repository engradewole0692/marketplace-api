<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistrationPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistrationPayment */
final class EventRegistrationPaymentResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'registration_id' => $this->registration?->uuid,
      'amount' => (float) $this->amount,
      'currency' => $this->currency,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'payment_method' => $this->payment_method instanceof \BackedEnum ? $this->payment_method->value : $this->payment_method,
      'coupon_id' => $this->coupon?->uuid,
      'donation_id' => $this->donation_id,
      'notes' => $this->notes,
      'paid_at' => $this->paid_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
