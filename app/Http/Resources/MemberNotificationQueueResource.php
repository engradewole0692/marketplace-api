<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberNotificationQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MemberNotificationQueue */
final class MemberNotificationQueueResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'member_id' => $this->member_id,
      'member' => $this->whenLoaded('member', fn () => $this->member ? [
        'id' => $this->member->id,
        'uuid' => $this->member->uuid,
        'name' => $this->member->fullName(),
        'email' => $this->member->email,
        'status' => $this->member->status instanceof \BackedEnum ? $this->member->status->value : $this->member->status,
      ] : null),
      'member_name' => $this->whenLoaded('member', fn () => $this->member?->fullName()),
      'member_email' => $this->whenLoaded('member', fn () => $this->member?->email),
      'channel' => $this->channel,
      'template' => $this->template,
      'notification_type' => $this->template,
      'payload' => $this->payload,
      'status' => $this->status,
      'attempts' => $this->attempts,
      'error' => $this->error,
      'last_error' => $this->error,
      'queued_at' => $this->queued_at?->toIso8601String(),
      'scheduled_at' => $this->scheduled_at?->toIso8601String(),
      'scheduled_for' => $this->scheduled_at?->toIso8601String(),
      'sent_at' => $this->sent_at?->toIso8601String(),
      'cancelled_at' => $this->cancelled_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
