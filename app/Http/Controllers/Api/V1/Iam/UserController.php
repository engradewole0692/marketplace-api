<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Iam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Iam\BulkUserRequest;
use App\Http\Requests\Iam\StoreUserRequest;
use App\Http\Requests\Iam\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\Iam\UserManagementService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserController extends ApiController
{
  public function index(Request $request, UserManagementService $service): JsonResponse
  {
    $this->authorize('viewAny', User::class);

    $paginator = $service->paginate($request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, AdminUserResource::class),
      message: 'Users retrieved.',
    );
  }

  public function store(StoreUserRequest $request, UserManagementService $service): JsonResponse
  {
    $user = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user)],
      message: 'User created.',
      status: 201,
    );
  }

  public function show(User $user): JsonResponse
  {
    $this->authorize('view', $user);
    $user->load(['roles', 'avatarMedia']);

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user)],
      message: 'User retrieved.',
    );
  }

  public function update(UpdateUserRequest $request, User $user, UserManagementService $service): JsonResponse
  {
    $user = $service->update($user, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user)],
      message: 'User updated.',
    );
  }

  public function destroy(User $user, UserManagementService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $user);
    $service->delete($user, $request->user());

    return $this->responder->success(message: 'User deleted.');
  }

  public function restore(int $userId, UserManagementService $service, Request $request): JsonResponse
  {
    $trashed = User::query()->onlyTrashed()->findOrFail($userId);
    $this->authorize('restore', $trashed);
    $user = $service->restore($userId, $request->user());

    return $this->responder->success(
      data: ['user' => new AdminUserResource($user)],
      message: 'User restored.',
    );
  }

  public function bulk(BulkUserRequest $request, UserManagementService $service): JsonResponse
  {
    $validated = $request->validated();
    $count = $service->bulk(
      \App\Enums\BulkUserAction::from($validated['action']),
      $validated['user_ids'],
      $request->user(),
    );

    return $this->responder->success(
      data: ['affected' => $count],
      message: 'Bulk action completed.',
    );
  }
}
