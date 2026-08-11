<?php

declare(strict_types=1);

namespace App\Modules\Communications\Policies;

use App\Models\User;

final class CommunicationPolicy
{
  public function manage(User $user): bool
  {
    return $user->hasAnyPermission(['settings.manage', 'cms.manage', 'communications.manage']);
  }
}
