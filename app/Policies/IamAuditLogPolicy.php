<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IamAuditLog;
use App\Models\User;

final class IamAuditLogPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('audit.view');
  }

  public function view(User $user, IamAuditLog $log): bool
  {
    return $user->hasPermission('audit.view');
  }
}
