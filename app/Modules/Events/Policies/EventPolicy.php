<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\ChecksEventScope;

final class EventPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['events.view', 'events.manage', 'events.staff']);
  }

  public function view(User $user, Event $event): bool
  {
    if (! $user->hasAnyPermission(['events.view', 'events.manage', 'events.staff'])) {
      return false;
    }

    return $this->eventIsAccessible($user, $event);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('events.manage');
  }

  public function update(User $user, Event $event): bool
  {
    if ($user->hasPermission('events.manage')) {
      return true;
    }

    return $user->hasPermission('events.staff') && $this->eventIsAccessible($user, $event);
  }

  public function delete(User $user, Event $event): bool
  {
    return $user->hasPermission('events.manage');
  }

  public function publish(User $user, Event $event): bool
  {
    if (! $user->hasAnyPermission(['events.publish', 'events.manage'])) {
      return false;
    }

    return $this->eventIsAccessible($user, $event);
  }
}
