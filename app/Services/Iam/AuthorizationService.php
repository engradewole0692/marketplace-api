<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Contracts\ServiceContract;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;

final class AuthorizationService implements ServiceContract
{
  /** @var array<int, list<string>> */
  private array $cache = [];

  public function userHasPermission(User $user, string $slug): bool
  {
    return in_array($slug, $this->permissionSlugsForUser($user), true);
  }

  /**
   * @return list<string>
   */
  public function permissionSlugsForUser(User $user): array
  {
    if (isset($this->cache[$user->id])) {
      return $this->cache[$user->id];
    }

    $user->loadMissing(['roles.permissions', 'permissions']);

    $rolePermissions = $user->roles
      ->flatMap(fn ($role) => $role->permissions->pluck('slug'));

    $directPermissions = $user->permissions->pluck('slug');

    $slugs = $rolePermissions
      ->merge($directPermissions)
      ->unique()
      ->sort()
      ->values()
      ->all();

    $this->cache[$user->id] = $slugs;

    return $slugs;
  }

  public function clearCacheForUser(User $user): void
  {
    unset($this->cache[$user->id]);
  }

  /**
   * @param  list<string>  $slugs
   */
  public function userHasAnyPermission(User $user, array $slugs): bool
  {
    $owned = $this->permissionSlugsForUser($user);

    foreach ($slugs as $slug) {
      if (in_array($slug, $owned, true)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @return Collection<int, Permission>
   */
  public function allPermissions(): Collection
  {
    return Permission::query()->orderBy('module')->orderBy('slug')->get();
  }
}
