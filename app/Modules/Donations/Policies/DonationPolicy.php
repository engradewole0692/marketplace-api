<?php

declare(strict_types=1);

namespace App\Modules\Donations\Policies;

use App\Models\User;
use App\Modules\Donations\Models\Donation;

final class DonationPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('donations.view') || $user->hasPermission('donations.manage');
  }

  public function view(User $user, Donation $donation): bool
  {
    return $this->viewAny($user);
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('donations.manage');
  }

  public function update(User $user, Donation $donation = null): bool
  {
    return $user->hasPermission('donations.manage') || $user->hasPermission('donations.confirm');
  }

  public function delete(User $user, Donation $donation): bool
  {
    return $user->hasPermission('donations.manage');
  }
}
