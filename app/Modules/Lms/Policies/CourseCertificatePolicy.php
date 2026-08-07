<?php

declare(strict_types=1);

namespace App\Modules\Lms\Policies;

use App\Models\User;
use App\Modules\Lms\Models\CertificateTemplate;
use App\Modules\Lms\Models\CourseCertificate;

final class CourseCertificatePolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasAnyPermission(['certificates.manage', 'courses.manage']);
  }

  public function view(User $user, CourseCertificate|CertificateTemplate $model): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasAnyPermission(['certificates.manage', 'courses.manage']);
  }

  public function update(User $user, CourseCertificate|CertificateTemplate $model): bool
  {
    return $this->create($user);
  }

  public function delete(User $user, CourseCertificate|CertificateTemplate $model): bool
  {
    return $this->create($user);
  }

  public function issue(User $user): bool
  {
    return $user->hasAnyPermission(['certificates.manage', 'courses.manage', 'courses.teach']);
  }
}
