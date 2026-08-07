<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Models\MemberNotificationQueue;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class MemberNotificationAdminService implements ServiceContract
{
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = MemberNotificationQueue::query()->with('member')->latest();

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['channel'])) {
      $query->where('channel', $filters['channel']);
    }

    if (! empty($filters['member_id'])) {
      $query->where('member_id', $filters['member_id']);
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('template', 'like', "%{$search}%")
          ->orWhere('channel', 'like', "%{$search}%")
          ->orWhereHas('member', function ($m) use ($search): void {
            $m->where('email', 'like', "%{$search}%")
              ->orWhere('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%");
          });
      });
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }

  public function markSent(MemberNotificationQueue $item, ?User $actor = null): MemberNotificationQueue
  {
    $item->status = 'sent';
    $item->sent_at = Carbon::now();
    $item->error = null;
    $item->attempts = (int) $item->attempts + 1;
    $item->save();

    return $item->fresh(['member']);
  }

  public function markFailed(MemberNotificationQueue $item, string $error): MemberNotificationQueue
  {
    $item->status = 'failed';
    $item->error = $error;
    $item->attempts = (int) $item->attempts + 1;
    $item->save();

    return $item->fresh(['member']);
  }

  public function retry(MemberNotificationQueue $item): MemberNotificationQueue
  {
    $item->status = 'pending';
    $item->error = null;
    $item->cancelled_at = null;
    $item->queued_at = Carbon::now();
    $item->scheduled_at = Carbon::now();
    $item->save();

    app(MemberNotificationQueueService::class)->dispatch($item);

    return $item->fresh(['member']);
  }

  public function cancel(MemberNotificationQueue $item): MemberNotificationQueue
  {
    $item->status = 'cancelled';
    $item->cancelled_at = Carbon::now();
    $item->save();

    return $item->fresh(['member']);
  }
}
