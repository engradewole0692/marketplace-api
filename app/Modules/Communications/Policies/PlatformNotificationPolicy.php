<?php

declare(strict_types=1);

namespace App\Modules\Communications\Policies;

use App\Models\User;
use App\Modules\Communications\Models\PlatformNotification;

final class PlatformNotificationPolicy
{
  public function manage(User $user): bool
  {
    return $user->hasAnyPermission(['notifications.manage', 'settings.manage', 'admin.access']);
  }
}
