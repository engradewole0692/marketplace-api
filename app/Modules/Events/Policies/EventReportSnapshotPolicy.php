<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventReportSnapshot;

final class EventReportSnapshotPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('reports.view');
  }

  public function view(User $user, EventReportSnapshot $report): bool
  {
    return $user->hasPermission('reports.view');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('reports.view');
  }
}
