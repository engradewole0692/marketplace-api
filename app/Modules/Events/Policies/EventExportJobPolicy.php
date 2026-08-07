<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventExportJob;

final class EventExportJobPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('exports.manage');
  }

  public function view(User $user, EventExportJob $exportJob): bool
  {
    return $user->hasPermission('exports.manage');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('exports.manage');
  }
}
