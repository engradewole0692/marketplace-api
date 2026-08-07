<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CmsAuditService implements ServiceContract
{
  public function record(
    CmsAuditEventType $eventType,
    string $entityType,
    ?int $entityId = null,
    ?User $actor = null,
    ?array $oldValues = null,
    ?array $newValues = null,
  ): CmsAuditLog {
    /** @var Request $request */
    $request = request();

    return CmsAuditLog::query()->create([
      'uuid' => (string) Str::uuid(),
      'event_type' => $eventType,
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'actor_id' => $actor?->id ?? $request->user()?->id,
      'old_values' => $oldValues,
      'new_values' => $newValues,
      'ip_address' => $request->ip(),
      'user_agent' => (string) $request->userAgent(),
      'created_at' => now(),
    ]);
  }
}
