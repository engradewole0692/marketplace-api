<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventReportSnapshot;
use App\Modules\Events\Support\ChecksEventScope;

final class EventReportSnapshotPolicy
{
  use ChecksEventScope;

  public function viewAny(User $user): bool
  {
    return $user->hasPermission('reports.view');
  }

  public function view(User $user, EventReportSnapshot $report): bool
  {
    if (! $user->hasPermission('reports.view')) {
      return false;
    }

    $report->loadMissing('event');

    return $this->eventIsAccessible($user, $report->event);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('reports.view');
  }
}
