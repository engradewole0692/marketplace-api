<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Speaker;

final class SpeakerPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['events.view', 'speakers.manage']);
  }

  public function view(User $user, Speaker $speaker): bool
  {
    return $user->hasAnyPermission(['events.view', 'speakers.manage']);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('speakers.manage');
  }

  public function update(User $user, Speaker $speaker): bool
  {
    return $user->hasPermission('speakers.manage');
  }

  public function delete(User $user, Speaker $speaker): bool
  {
    return $user->hasPermission('speakers.manage');
  }
}
