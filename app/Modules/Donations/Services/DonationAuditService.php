<?php

declare(strict_types=1);

namespace App\Modules\Donations\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Donations\Models\DonationAuditLog;

final class DonationAuditService implements ServiceContract
{
  public function record(
    string $eventType,
    string $entityType,
    ?int $entityId,
    ?User $actor,
    ?array $old = null,
    ?array $new = null,
  ): void {
    DonationAuditLog::query()->create([
      'event_type' => $eventType,
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'actor_id' => $actor?->id,
      'old_values' => $old,
      'new_values' => $new,
      'ip_address' => request()->ip(),
      'user_agent' => (string) request()->userAgent(),
    ]);
  }
}
