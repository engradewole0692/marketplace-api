<?php

declare(strict_types=1);

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\EventCertificateTemplate;

final class EventCertificateTemplatePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['certificates.manage', 'certificates.issue', 'events.manage']);
  }

  public function view(User $user, EventCertificateTemplate $template): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['certificates.manage', 'events.manage']);
  }

  public function update(User $user, EventCertificateTemplate $template): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, EventCertificateTemplate $template): bool
  {
    return $this->create($user);
  }
}
