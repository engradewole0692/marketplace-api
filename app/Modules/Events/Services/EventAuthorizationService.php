<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStaffAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class EventAuthorizationService implements ServiceContract
{
  public const GLOBAL_PERMISSION = 'events.manage';

  public const STAFF_PERMISSION = 'events.staff';

  public function isGlobalEventAdmin(User $user): bool
  {
    return $user->hasPermission(self::GLOBAL_PERMISSION);
  }

  public function isEventScopedStaff(User $user): bool
  {
    return $user->hasPermission(self::STAFF_PERMISSION) && ! $this->isGlobalEventAdmin($user);
  }

  /**
   * @return list<int>
   */
  public function assignedEventIds(User $user): array
  {
    return EventStaffAssignment::query()
      ->where('user_id', $user->id)
      ->where('is_active', true)
      ->pluck('event_id')
      ->map(fn ($id): int => (int) $id)
      ->all();
  }

  public function isAssignedToEvent(User $user, Event $event): bool
  {
    return EventStaffAssignment::query()
      ->where('user_id', $user->id)
      ->where('event_id', $event->id)
      ->where('is_active', true)
      ->exists();
  }

  public function canAccessEvent(User $user, ?Event $event): bool
  {
    if ($this->isGlobalEventAdmin($user)) {
      return true;
    }

    if ($event === null) {
      return ! $this->isEventScopedStaff($user);
    }

    if ($this->isEventScopedStaff($user)) {
      return $this->isAssignedToEvent($user, $event);
    }

    return true;
  }

  public function assertAccess(User $user, ?Event $event): void
  {
    if (! $this->canAccessEvent($user, $event)) {
      throw new AuthorizationException;
    }
  }

  public function assertEventIdAccess(User $user, ?int $eventId): void
  {
    if ($this->isGlobalEventAdmin($user)) {
      return;
    }

    if (! $this->isEventScopedStaff($user)) {
      return;
    }

    if ($eventId === null || $eventId === 0) {
      throw new AuthorizationException;
    }

    $event = Event::query()->find($eventId);
    if ($event === null || ! $this->isAssignedToEvent($user, $event)) {
      throw new AuthorizationException;
    }
  }

  public function restrictEventsQuery(Builder $query, ?User $user): Builder
  {
    if ($user === null || ! $this->isEventScopedStaff($user)) {
      return $query;
    }

    $ids = $this->assignedEventIds($user);
    if ($ids === []) {
      return $query->whereRaw('1 = 0');
    }

    return $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
  }

  public function restrictEventOwnedQuery(Builder $query, ?User $user, string $column = 'event_id'): Builder
  {
    if ($user === null || ! $this->isEventScopedStaff($user)) {
      return $query;
    }

    $ids = $this->assignedEventIds($user);
    if ($ids === []) {
      return $query->whereRaw('1 = 0');
    }

    return $query->whereIn($column, $ids);
  }
}
