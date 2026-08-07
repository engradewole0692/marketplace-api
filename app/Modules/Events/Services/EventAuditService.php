<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAuditLog;

final class EventAuditService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $oldValues
   * @param  array<string, mixed>|null  $newValues
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    EventAuditEventType $eventType,
    ?Event $event = null,
    ?User $actor = null,
    ?string $subjectType = null,
    ?int $subjectId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?array $metadata = null,
  ): void {
    EventAuditLog::query()->create([
      'event_type' => $eventType->value,
      'event_id' => $event?->id,
      'actor_id' => $actor?->id,
      'subject_type' => $subjectType,
      'subject_id' => $subjectId,
      'old_values' => $oldValues,
      'new_values' => $newValues,
      'metadata' => $metadata,
      'ip_address' => request()?->ip(),
      'user_agent' => request()?->userAgent(),
    ]);
  }
}
