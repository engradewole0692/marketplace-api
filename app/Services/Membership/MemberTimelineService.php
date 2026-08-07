<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberTimeline;
use App\Models\User;

final class MemberTimelineService implements ServiceContract
{
  public function record(
    Member $member,
    MemberTimelineEventType $eventType,
    string $description,
    ?User $actor = null,
    ?array $metadata = null,
  ): MemberTimeline {
    return MemberTimeline::query()->create([
      'member_id' => $member->id,
      'event_type' => $eventType,
      'description' => $description,
      'actor_id' => $actor?->id,
      'metadata' => $metadata,
      'occurred_at' => now(),
    ]);
  }

  /**
   * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, MemberTimeline>
   */
  public function paginate(Member $member, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
  {
    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $member->timelines()
      ->with('actor')
      ->orderByDesc('occurred_at')
      ->paginate($perPage);
  }
}
