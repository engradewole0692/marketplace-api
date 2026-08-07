<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingCaseEvent;

final class CounsellingAuditService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    CounsellingCase $case,
    ?User $actor,
    string $eventType,
    string $title,
    ?string $description = null,
    ?array $metadata = null,
  ): CounsellingCaseEvent {
    return CounsellingCaseEvent::query()->create([
      'case_id' => $case->id,
      'actor_user_id' => $actor?->id,
      'event_type' => $eventType,
      'title' => $title,
      'description' => $description,
      'metadata' => $metadata,
      'occurred_at' => now(),
    ]);
  }
}
