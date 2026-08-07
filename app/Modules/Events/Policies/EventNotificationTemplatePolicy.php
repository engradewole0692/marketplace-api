<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventNotificationTemplate;

final class EventNotificationTemplatePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('event_notifications.manage');
  }

  public function view(User $user, EventNotificationTemplate $template): bool
  {
    return $user->hasPermission('event_notifications.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('event_notifications.manage');
  }
}
