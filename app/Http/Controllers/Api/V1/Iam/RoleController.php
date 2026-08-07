<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Iam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Iam\StoreRoleRequest;
use App\Http\Requests\Iam\UpdateRoleRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\Iam\RoleManagementService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleController extends ApiController
{
  public function index(Request $request, RoleManagementService $service): JsonResponse
  {
    $this->authorize('viewAny', Role::class);

    $paginator = $service->paginate($request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, RoleResource::class),
      message: 'Roles retrieved.',
    );
  }

  public function store(StoreRoleRequest $request, RoleManagementService $service): JsonResponse
  {
    $role = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['role' => new RoleResource($role)],
      message: 'Role created.',
      status: 201,
    );
  }

  public function show(Role $role): JsonResponse
  {
    $this->authorize('view', $role);
    $role->load(['permissions'])->loadCount('users');

    return $this->responder->success(
      data: ['role' => new RoleResource($role)],
      message: 'Role retrieved.',
    );
  }

  public function update(UpdateRoleRequest $request, Role $role, RoleManagementService $service): JsonResponse
  {
    $role = $service->update($role, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['role' => new RoleResource($role)],
      message: 'Role updated.',
    );
  }

  public function destroy(Role $role, RoleManagementService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $role);
    $service->delete($role, $request->user());

    return $this->responder->success(message: 'Role deleted.');
  }

  public function clone(Role $role, RoleManagementService $service, Request $request): JsonResponse
  {
    $this->authorize('clone', $role);

    $clone = $service->clone($role, $request->user(), $request->input('name'));

    return $this->responder->success(
      data: ['role' => new RoleResource($clone)],
      message: 'Role cloned.',
      status: 201,
    );
  }

  public function users(Role $role): JsonResponse
  {
    $this->authorize('view', $role);

    $users = $role->users()->paginate(25);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($users, AdminUserResource::class),
      message: 'Role users retrieved.',
    );
  }
}
