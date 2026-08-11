<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Communications\Models\CommunicationEmailLog */
final class CommunicationEmailLogResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'template' => $this->whenLoaded('template', fn () => $this->template ? [
        'id' => $this->template->uuid,
        'name' => $this->template->name,
        'slug' => $this->template->slug,
        'event_key' => $this->template->event_key,
      ] : null),
      'event_key' => $this->event_key,
      'section' => $this->section,
      'recipient_email' => $this->recipient_email,
      'sender_email' => $this->sender_email,
      'subject' => $this->subject,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'is_test' => (bool) $this->is_test,
      'error_message' => $this->error_message,
      'related_type' => $this->related_type,
      'related_id' => $this->related_id,
      'metadata' => $this->metadata ?? [],
      'sent_at' => $this->sent_at?->toIso8601String(),
      'failed_at' => $this->failed_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
