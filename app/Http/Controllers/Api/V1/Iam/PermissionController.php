<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Iam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Services\Iam\PermissionManagementService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PermissionController extends ApiController
{
  public function index(Request $request, PermissionManagementService $service): JsonResponse
  {
    $this->authorize('viewAny', Permission::class);

    if ($request->boolean('grouped')) {
      $grouped = $service->grouped()->map(
        fn ($permissions, $module) => [
          'module' => $module,
          'permissions' => PermissionResource::collection($permissions),
        ],
      )->values();

      return $this->responder->success(
        data: ['groups' => $grouped],
        message: 'Permissions retrieved.',
      );
    }

    $paginator = $service->paginate($request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, PermissionResource::class),
      message: 'Permissions retrieved.',
    );
  }

  public function show(Permission $permission): JsonResponse
  {
    $this->authorize('view', $permission);

    return $this->responder->success(
      data: ['permission' => new PermissionResource($permission)],
      message: 'Permission retrieved.',
    );
  }
}
