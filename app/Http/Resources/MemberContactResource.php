<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberContact
 */
final class MemberContactResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'contact_type' => $this->contact_type,
      'name' => $this->name,
      'relationship' => $this->relationship,
      'phone' => $this->phone,
      'email' => $this->email,
      'is_primary' => $this->is_primary,
    ];
  }
}
