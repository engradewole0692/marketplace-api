<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;

final class EventPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['events.view', 'events.manage']);
  }

  public function view(User $user, Event $event): bool
  {
    return $user->hasAnyPermission(['events.view', 'events.manage']);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('events.manage');
  }

  public function update(User $user, Event $event): bool
  {
    return $user->hasPermission('events.manage');
  }

  public function delete(User $user, Event $event): bool
  {
    return $user->hasPermission('events.manage');
  }

  public function publish(User $user, Event $event): bool
  {
    return $user->hasAnyPermission(['events.publish', 'events.manage']);
  }
}
