<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Contracts\ServiceContract;
use App\Enums\IamAuditEventType;
use App\Models\IamAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

final class IamAuditService implements ServiceContract
{
  public function record(
    IamAuditEventType $eventType,
    ?User $actor = null,
    ?string $subjectType = null,
    ?int $subjectId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?array $metadata = null,
    ?Request $request = null,
  ): IamAuditLog {
    $request ??= request();

    return IamAuditLog::query()->create([
      'event_type' => $eventType->value,
      'actor_id' => $actor?->id,
      'subject_type' => $subjectType,
      'subject_id' => $subjectId,
      'old_values' => $oldValues,
      'new_values' => $newValues,
      'metadata' => $metadata,
      'ip_address' => $request?->ip(),
      'user_agent' => $request?->userAgent(),
    ]);
  }
}
