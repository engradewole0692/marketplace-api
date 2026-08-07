<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;

final class RegistrationAuditService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $oldValues
   * @param  array<string, mixed>|null  $newValues
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    RegistrationAuditEventType $eventType,
    EventRegistration $registration,
    ?User $actor = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?array $metadata = null,
  ): void {
    EventRegistrationAuditLog::query()->create([
      'event_type' => $eventType->value,
      'registration_id' => $registration->id,
      'event_id' => $registration->event_id,
      'member_id' => $registration->member_id,
      'actor_id' => $actor?->id,
      'old_values' => $oldValues,
      'new_values' => $newValues,
      'metadata' => $metadata,
      'ip_address' => request()?->ip(),
      'user_agent' => request()?->userAgent(),
    ]);
  }
}
