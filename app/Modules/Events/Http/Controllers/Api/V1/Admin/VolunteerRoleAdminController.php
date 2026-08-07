<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreVolunteerRoleRequest;
use App\Modules\Events\Http\Resources\EventVolunteerRoleResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventVolunteerRole;
use App\Modules\Events\Services\VolunteerService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VolunteerRoleAdminController extends ApiController
{
  public function index(Request $request, Event $event, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->listRoles($event->id, $request->query()),
        EventVolunteerRoleResource::class,
      ),
      message: 'Volunteer roles retrieved.',
    );
  }

  public function store(StoreVolunteerRoleRequest $request, Event $event, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    $role = $service->createRole($event, $request->validated());

    return $this->responder->success(
      data: ['role' => new EventVolunteerRoleResource($role)],
      message: 'Volunteer role created.',
      status: 201,
    );
  }

  public function update(
    StoreVolunteerRoleRequest $request,
    EventVolunteerRole $role,
    VolunteerService $service,
  ): JsonResponse {
    $this->authorize('permission', 'volunteers.manage');

    $role = $service->updateRole($role, $request->validated());

    return $this->responder->success(
      data: ['role' => new EventVolunteerRoleResource($role)],
      message: 'Volunteer role updated.',
    );
  }

  public function destroy(EventVolunteerRole $role, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    $service->deleteRole($role);

    return $this->responder->success(data: null, message: 'Volunteer role deleted.');
  }
}
