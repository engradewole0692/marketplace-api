<?php

declare(strict_types=1);

namespace App\Modules\Communications\Policies;

use App\Models\User;
use App\Modules\Communications\Models\PlatformAnnouncement;

final class PlatformAnnouncementPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['notifications.manage', 'cms.manage', 'settings.manage', 'admin.access']);
  }

  public function view(User $user, PlatformAnnouncement $announcement): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['notifications.manage', 'settings.manage']);
  }

  public function update(User $user, PlatformAnnouncement $announcement): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, PlatformAnnouncement $announcement): bool
  {
    return $this->create($user);
  }
}
