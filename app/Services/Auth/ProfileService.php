<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\ServiceContract;
use App\Models\User;

final class ProfileService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $attributes
   */
  public function update(User $user, array $attributes): User
  {
    $user->fill([
      'first_name' => $attributes['first_name'] ?? $user->first_name,
      'last_name' => $attributes['last_name'] ?? $user->last_name,
      'display_name' => $attributes['display_name'] ?? $user->display_name,
      'phone' => $attributes['phone'] ?? $user->phone,
      'timezone' => $attributes['timezone'] ?? $user->timezone,
      'locale' => $attributes['locale'] ?? $user->locale,
    ]);

    $user->syncDerivedNames();
    $user->save();

    return $user->fresh();
  }
}
