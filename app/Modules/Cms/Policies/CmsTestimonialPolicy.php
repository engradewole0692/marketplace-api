<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Models\User;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Support\CmsPermission;

final class CmsTestimonialPolicy
{
  public function viewAny(User $user): bool
  {
    return CmsPermission::allows($user, 'cms.testimonials.manage');
  }

  public function view(User $user, CmsTestimonial $testimonial): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $this->viewAny($user);
  }

  public function update(User $user, CmsTestimonial $testimonial): bool
  {
    return $this->viewAny($user);
  }

  public function delete(User $user, CmsTestimonial $testimonial): bool
  {
    return $this->viewAny($user);
  }
}
