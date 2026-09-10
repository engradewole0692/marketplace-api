<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventNotificationTemplate;
use App\Modules\Events\Support\ChecksEventScope;

final class EventNotificationTemplatePolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasPermission('event_notifications.manage');
  }

  public function view(User $user, EventNotificationTemplate $template): bool
  {
    if (! $user->hasPermission('event_notifications.manage')) {
      return false;
    }

    $template->loadMissing('event');

    return $this->eventIsAccessible($user, $template->event);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('event_notifications.manage');
  }
}
