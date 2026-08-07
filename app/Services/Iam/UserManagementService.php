<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Contracts\ServiceContract;
use App\Enums\BulkUserAction;
use App\Enums\IamAuditEventType;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UserManagementService implements ServiceContract
{
  public function __construct(
    private readonly IamAuditService $auditService,
    private readonly AuthorizationService $authorizationService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = User::query()->with(['roles', 'avatarMedia']);

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('email', 'like', "%{$search}%")
          ->orWhere('first_name', 'like', "%{$search}%")
          ->orWhere('last_name', 'like', "%{$search}%")
          ->orWhere('display_name', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['role'])) {
      $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role']));
    }

    if (! empty($filters['trashed']) && $filters['trashed'] === 'only') {
      $query->onlyTrashed();
    } elseif (! empty($filters['trashed']) && $filters['trashed'] === 'with') {
      $query->withTrashed();
    }

    $sort = (string) ($filters['sort'] ?? 'created_at');
    $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $allowedSorts = ['created_at', 'email', 'first_name', 'last_name', 'status', 'last_login_at'];

    if (! in_array($sort, $allowedSorts, true)) {
      $sort = 'created_at';
    }

    $query->orderBy($sort, $direction);

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): User
  {
    return DB::transaction(function () use ($data, $actor): User {
      $user = User::query()->create([
        'first_name' => $data['first_name'] ?? null,
        'last_name' => $data['last_name'] ?? null,
        'display_name' => $data['display_name'] ?? null,
        'email' => $data['email'],
        'phone' => $data['phone'] ?? null,
        'status' => $data['status'] ?? UserStatus::Active->value,
        'password' => Hash::make((string) $data['password']),
        'timezone' => $data['timezone'] ?? 'UTC',
        'locale' => $data['locale'] ?? 'en',
      ]);

      if (! empty($data['role_ids'])) {
        $user->roles()->sync($data['role_ids']);
        $this->auditService->record(
          IamAuditEventType::RoleAssigned,
          $actor,
          User::class,
          $user->id,
          null,
          ['role_ids' => $data['role_ids']],
        );
      }

      $this->auditService->record(
        IamAuditEventType::UserCreated,
        $actor,
        User::class,
        $user->id,
        null,
        Arr::only($user->toArray(), ['email', 'status']),
      );

      return $user->fresh(['roles']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(User $user, array $data, User $actor): User
  {
    return DB::transaction(function () use ($user, $data, $actor): User {
      $old = Arr::only($user->toArray(), ['email', 'status', 'first_name', 'last_name', 'phone']);

      $payload = Arr::only($data, [
        'first_name', 'last_name', 'display_name', 'email', 'phone', 'status', 'timezone', 'locale',
        'must_change_password',
      ]);

      if (! empty($data['password'])) {
        $payload['password'] = Hash::make((string) $data['password']);
        $payload['must_change_password'] = array_key_exists('must_change_password', $data)
          ? (bool) $data['must_change_password']
          : true;
      }

      $user->fill($payload);
      $user->save();

      if (array_key_exists('role_ids', $data)) {
        $oldRoleIds = $user->roles()->pluck('roles.id')->all();
        $user->roles()->sync($data['role_ids'] ?? []);
        $this->auditService->record(
          IamAuditEventType::RoleAssigned,
          $actor,
          User::class,
          $user->id,
          ['role_ids' => $oldRoleIds],
          ['role_ids' => $data['role_ids'] ?? []],
        );
      }

      $this->auditService->record(
        IamAuditEventType::UserUpdated,
        $actor,
        User::class,
        $user->id,
        $old,
        Arr::only($user->fresh()->toArray(), ['email', 'status', 'first_name', 'last_name', 'phone']),
      );

      $this->authorizationService->clearCacheForUser($user);

      return $user->fresh(['roles']);
    });
  }

  public function delete(User $user, User $actor): void
  {
    $user->delete();

    $this->auditService->record(
      IamAuditEventType::UserDeleted,
      $actor,
      User::class,
      $user->id,
    );
  }

  public function restore(int $userId, User $actor): User
  {
    $user = User::query()->onlyTrashed()->findOrFail($userId);
    $user->restore();

    $this->auditService->record(
      IamAuditEventType::UserRestored,
      $actor,
      User::class,
      $user->id,
    );

    return $user->fresh(['roles']);
  }

  /**
   * @param  list<int>  $userIds
   */
  public function bulk(BulkUserAction $action, array $userIds, User $actor): int
  {
    $count = 0;

    foreach ($userIds as $userId) {
      $user = User::query()->withTrashed()->find($userId);
      if ($user === null) {
        continue;
      }

      match ($action) {
        BulkUserAction::Delete => $this->delete($user, $actor),
        BulkUserAction::Restore => $this->restore((int) $user->id, $actor),
        BulkUserAction::Activate => $this->setStatus($user, UserStatus::Active, $actor),
        BulkUserAction::Deactivate => $this->setStatus($user, UserStatus::Inactive, $actor),
      };

      $count++;
    }

    $this->auditService->record(
      IamAuditEventType::UserBulkAction,
      $actor,
      metadata: ['action' => $action->value, 'user_ids' => $userIds, 'count' => $count],
    );

    return $count;
  }

  private function setStatus(User $user, UserStatus $status, User $actor): void
  {
    $old = $user->status()->value;
    $user->status = $status;
    $user->save();

    $this->auditService->record(
      IamAuditEventType::StatusChanged,
      $actor,
      User::class,
      $user->id,
      ['status' => $old],
      ['status' => $status->value],
    );
  }
}
