<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

final class MemberAuditService implements ServiceContract
{
  public function __construct(
    private readonly MemberTimelineService $timelineService,
  ) {}

  public function record(
    MemberAuditEventType $eventType,
    Member $member,
    ?User $actor = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?array $metadata = null,
    ?Request $request = null,
  ): void {
    $request ??= request();

    MemberAuditLog::query()->create([
      'event_type' => $eventType->value,
      'member_id' => $member->id,
      'actor_id' => $actor?->id,
      'old_values' => $oldValues,
      'new_values' => $newValues,
      'metadata' => $metadata,
      'ip_address' => $request?->ip(),
      'user_agent' => $request?->userAgent(),
    ]);
  }

  public function recordWithTimeline(
    MemberAuditEventType $auditEvent,
    MemberTimelineEventType $timelineEvent,
    Member $member,
    string $description,
    ?User $actor = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?array $metadata = null,
  ): void {
    $this->record($auditEvent, $member, $actor, $oldValues, $newValues, $metadata);
    $this->timelineService->record($member, $timelineEvent, $description, $actor, $metadata);
  }
}
