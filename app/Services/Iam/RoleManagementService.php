<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Contracts\ServiceContract;
use App\Enums\IamAuditEventType;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleManagementService implements ServiceContract
{
  public function __construct(
    private readonly IamAuditService $auditService,
    private readonly AuthorizationService $authorizationService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Role::query()->withCount('users', 'permissions');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('name', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%");
      });
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->orderBy('name')->paginate($perPage);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, \App\Models\User $actor): Role
  {
    return DB::transaction(function () use ($data, $actor): Role {
      $role = Role::query()->create([
        'name' => $data['name'],
        'slug' => $data['slug'] ?? Str::slug((string) $data['name'], '_'),
        'guard_name' => $data['guard_name'] ?? 'web',
        'description' => $data['description'] ?? null,
        'is_system' => false,
      ]);

      if (! empty($data['permission_ids'])) {
        $role->permissions()->sync($data['permission_ids']);
      }

      $this->auditService->record(
        IamAuditEventType::RoleCreated,
        $actor,
        Role::class,
        $role->id,
        null,
        Arr::only($role->toArray(), ['name', 'slug']),
      );

      return $role->fresh(['permissions']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Role $role, array $data, \App\Models\User $actor): Role
  {
    return DB::transaction(function () use ($role, $data, $actor): Role {
      $old = Arr::only($role->toArray(), ['name', 'slug', 'description']);

      $role->fill(Arr::only($data, ['name', 'slug', 'description', 'guard_name']));
      $role->save();

      if (array_key_exists('permission_ids', $data)) {
        $oldPermissionIds = $role->permissions()->pluck('permissions.id')->all();
        $role->permissions()->sync($data['permission_ids'] ?? []);
        $this->auditService->record(
          IamAuditEventType::PermissionAssigned,
          $actor,
          Role::class,
          $role->id,
          ['permission_ids' => $oldPermissionIds],
          ['permission_ids' => $data['permission_ids'] ?? []],
        );
      }

      $this->auditService->record(
        IamAuditEventType::RoleUpdated,
        $actor,
        Role::class,
        $role->id,
        $old,
        Arr::only($role->fresh()->toArray(), ['name', 'slug', 'description']),
      );

      return $role->fresh(['permissions']);
    });
  }

  public function delete(Role $role, \App\Models\User $actor): void
  {
    if ($role->is_system) {
      throw new \App\Exceptions\BusinessException(
        'System roles cannot be deleted.',
        \App\Enums\ApiErrorCode::Forbidden,
        null,
        403,
      );
    }

    $role->delete();

    $this->auditService->record(
      IamAuditEventType::RoleDeleted,
      $actor,
      Role::class,
      $role->id,
    );
  }

  public function clone(Role $role, \App\Models\User $actor, ?string $name = null): Role
  {
    $clone = Role::query()->create([
      'name' => $name ?? $role->name.' (Copy)',
      'slug' => Str::slug($name ?? $role->name.' copy', '_').'_'.Str::random(4),
      'guard_name' => $role->guard_name,
      'description' => $role->description,
      'is_system' => false,
    ]);

    $clone->permissions()->sync($role->permissions()->pluck('permissions.id'));

    $this->auditService->record(
      IamAuditEventType::RoleCloned,
      $actor,
      Role::class,
      $clone->id,
      null,
      ['source_role_id' => $role->id],
    );

    return $clone->fresh(['permissions']);
  }
}
