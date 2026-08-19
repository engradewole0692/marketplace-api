<?php

declare(strict_types=1);

namespace App\Modules\Communications\Policies;

use App\Models\User;
use App\Modules\Communications\Models\BulkEmailJob;

final class BulkEmailPolicy
{
  public function manage(User $user): bool
  {
    return $user->hasAnyPermission(['notifications.manage', 'newsletter.manage', 'settings.manage']);
  }
}
