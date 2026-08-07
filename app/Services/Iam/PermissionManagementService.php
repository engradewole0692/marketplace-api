<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Contracts\ServiceContract;
use App\Models\Permission;
use Illuminate\Support\Collection;

final class PermissionManagementService implements ServiceContract
{
  /**
   * @return Collection<string, Collection<int, Permission>>
   */
  public function grouped(): Collection
  {
    return Permission::query()
      ->orderBy('module')
      ->orderBy('group')
      ->orderBy('slug')
      ->get()
      ->groupBy('module');
  }

  public function paginate(array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
  {
    $query = Permission::query();

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('name', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('module', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['module'])) {
      $query->where('module', $filters['module']);
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 200);

    return $query->orderBy('module')->orderBy('slug')->paginate($perPage);
  }
}
