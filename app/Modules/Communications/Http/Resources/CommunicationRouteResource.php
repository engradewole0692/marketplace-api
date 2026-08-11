<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Communications\Models\CommunicationRoute */
final class CommunicationRouteResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'section' => $this->section,
      'event_key' => $this->event_key,
      'label' => $this->label,
      'recipient_role' => $this->recipient_role,
      'recipient_type' => $this->recipient_type,
      'email' => $this->email,
      'user_id' => $this->whenLoaded('user', fn () => $this->user?->uuid),
      'user' => $this->whenLoaded('user', fn () => $this->user ? [
        'id' => $this->user->uuid,
        'name' => $this->user->display_name ?: $this->user->name,
        'email' => $this->user->email,
      ] : null),
      'sort_order' => (int) $this->sort_order,
      'include_section_fallback' => (bool) $this->include_section_fallback,
      'include_ministry_fallback' => (bool) $this->include_ministry_fallback,
      'is_active' => (bool) $this->is_active,
      'metadata' => $this->metadata ?? [],
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
