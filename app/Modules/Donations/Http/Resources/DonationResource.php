<?php

declare(strict_types=1);

namespace App\Modules\Donations\Http\Resources;

use App\Modules\Donations\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Donation */
final class DonationResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'reference' => $this->reference,
      'amount' => (float) $this->amount,
      'currency' => $this->currency,
      'status' => $this->status?->value,
      'frequency' => $this->frequency?->value,
      'is_anonymous' => (bool) $this->is_anonymous,
      'needs_tax_receipt' => (bool) $this->needs_tax_receipt,
      'donor_name' => $this->displayDonorName(),
      'donor_email' => $this->donor_email,
      'donor_phone' => $this->donor_phone,
      'payment_method' => $this->payment_method?->value,
      'provider' => $this->provider,
      'provider_intent_id' => $this->provider_intent_id,
      'notes' => $this->notes,
      'fund' => $this->whenLoaded('fund', fn () => [
        'id' => $this->fund?->uuid,
        'name' => $this->fund?->name,
        'type' => $this->fund?->type?->value,
      ]),
      'country' => $this->whenLoaded('country', fn () => [
        'id' => $this->country?->uuid,
        'slug' => $this->country?->slug,
        'name' => $this->country?->name,
      ]),
      'receipt' => $this->whenLoaded('receipt', fn () => $this->receipt ? [
        'id' => $this->receipt->uuid,
        'number' => $this->receipt->number,
        'type' => $this->receipt->type?->value,
        'url' => $this->receipt->url(),
        'issued_at' => $this->receipt->issued_at?->toIso8601String(),
      ] : null),
      'paid_at' => $this->paid_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
