<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberAddress
 */
final class MemberAddressResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'address_type' => $this->address_type,
      'address_line_1' => $this->address_line_1,
      'address_line_2' => $this->address_line_2,
      'city' => $this->city,
      'state' => $this->state,
      'postal_code' => $this->postal_code,
      'country_code' => $this->country_code,
      'is_primary' => $this->is_primary,
    ];
  }
}
