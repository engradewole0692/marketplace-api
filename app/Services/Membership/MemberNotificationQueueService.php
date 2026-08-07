<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Jobs\ProcessMemberNotificationJob;
use App\Models\Member;
use App\Models\MemberNotificationQueue;
use Illuminate\Support\Carbon;

final class MemberNotificationQueueService implements ServiceContract
{
  /**
   * @var list<string>
   */
  public const CHANNELS = ['email', 'whatsapp', 'in_app', 'push', 'sms'];

  /**
   * @param  array<string, mixed>  $payload
   */
  public function queue(
    Member $member,
    string $channel,
    string $template,
    array $payload = [],
    ?Carbon $scheduledAt = null,
  ): MemberNotificationQueue {
    $item = MemberNotificationQueue::query()->create([
      'member_id' => $member->id,
      'channel' => $channel,
      'template' => $template,
      'payload' => $payload,
      'status' => 'pending',
      'attempts' => 0,
      'queued_at' => Carbon::now(),
      'scheduled_at' => $scheduledAt ?? Carbon::now(),
    ]);

    $this->dispatch($item);

    return $item;
  }

  /**
   * @param  list<array{channel: string, template: string, payload?: array<string, mixed>, scheduled_at?: Carbon|null}>  $items
   */
  public function queueMany(Member $member, array $items): void
  {
    foreach ($items as $item) {
      $this->queue(
        $member,
        $item['channel'],
        $item['template'],
        $item['payload'] ?? [],
        $item['scheduled_at'] ?? null,
      );
    }
  }

  public function dispatch(MemberNotificationQueue $item): void
  {
    // Process immediately so admin/portal status advances without requiring a queue worker.
    // Future-dated items remain delayed on the configured queue connection.
    if ($item->scheduled_at && $item->scheduled_at->isFuture()) {
      ProcessMemberNotificationJob::dispatch($item->id)->delay($item->scheduled_at);

      return;
    }

    ProcessMemberNotificationJob::dispatchSync($item->id);
  }

  public function processPending(int $limit = 50): int
  {
    $items = MemberNotificationQueue::query()
      ->where('status', 'pending')
      ->where(function ($query): void {
        $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
      })
      ->orderBy('id')
      ->limit($limit)
      ->get();

    foreach ($items as $item) {
      $this->dispatch($item);
    }

    return $items->count();
  }
}
