<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

final class MemberPolicy
{
  public function viewAny(User $user): bool
  {
    return $user->hasPermission('members.view');
  }

  public function view(User $user, Member $member): bool
  {
    return $user->hasPermission('members.view');
  }

  public function create(User $user): bool
  {
    return $user->hasPermission('members.create');
  }

  public function update(User $user, Member $member): bool
  {
    return $user->hasPermission('members.update');
  }

  public function delete(User $user, Member $member): bool
  {
    return $user->hasPermission('members.delete');
  }

  public function restore(User $user, Member $member): bool
  {
    return $user->hasPermission('members.restore');
  }

  public function approve(User $user, Member $member): bool
  {
    return $user->hasAnyPermission(['members.approve', 'members.manage']);
  }

  public function activate(User $user, Member $member): bool
  {
    return $user->hasAnyPermission(['members.activate', 'members.manage']);
  }

  public function reject(User $user, Member $member): bool
  {
    return $user->hasAnyPermission(['members.reject', 'members.approve', 'members.manage']);
  }

  public function archive(User $user, Member $member): bool
  {
    return $user->hasPermission('members.archive');
  }

  public function export(User $user): bool
  {
    return $user->hasPermission('members.export');
  }

  public function bulk(User $user): bool
  {
    return $user->hasAnyPermission([
      'members.approve',
      'members.delete',
      'members.restore',
      'members.archive',
      'members.update',
    ]);
  }
}
