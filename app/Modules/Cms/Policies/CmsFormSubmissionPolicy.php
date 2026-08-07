<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsFormSubmission;

final class CmsFormSubmissionPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('cms.manage');
  }

  public function view(User $user, CmsFormSubmission $submission): bool
  {
    return $user->hasPermission('cms.manage');
  }

  public function update(User $user, CmsFormSubmission $submission): bool
  {
    return $user->hasPermission('cms.manage');
  }

  public function delete(User $user, CmsFormSubmission $submission): bool
  {
    return $user->hasPermission('cms.manage');
  }
}
