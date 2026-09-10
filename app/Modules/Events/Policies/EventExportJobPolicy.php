<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventExportJob;
use App\Modules\Events\Support\ChecksEventScope;

final class EventExportJobPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasPermission('exports.manage');
  }

  public function view(User $user, EventExportJob $exportJob): bool
  {
    if (! $user->hasPermission('exports.manage')) {
      return false;
    }

    $exportJob->loadMissing('event');

    return $this->eventIsAccessible($user, $exportJob->event);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('exports.manage');
  }
}
