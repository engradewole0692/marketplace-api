<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IamAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IamAuditLog
 */
final class IamAuditLogResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'event_type' => $this->event_type,
      'actor' => new AdminUserResource($this->whenLoaded('actor')),
      'subject_type' => $this->subject_type,
      'subject_id' => $this->subject_id,
      'old_values' => $this->old_values,
      'new_values' => $this->new_values,
      'metadata' => $this->metadata,
      'ip_address' => $this->ip_address,
      'user_agent' => $this->user_agent,
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
