<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Events\Http\Resources\EventStaffAssignmentResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStaffAssignment;
use App\Modules\Events\Services\EventStaffAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventStaffAdminController extends ApiController
{
  public function searchUsers(Request $request, EventStaffAssignmentService $service): JsonResponse
  {
    $this->authorize('permission', 'events.manage');

    $request->validate([
      'q' => ['required', 'string', 'min:2', 'max:120'],
    ]);

    return $this->responder->success(
      data: ['users' => $service->searchUsers((string) $request->query('q'))],
      message: 'Users retrieved.',
    );
  }

  public function index(Event $event, EventStaffAssignmentService $service): JsonResponse
  {
    $this->authorize('view', $event);

    return $this->responder->success(
      data: [
        'staff' => array_map(
          fn (EventStaffAssignment $assignment) => (new EventStaffAssignmentResource($assignment))->resolve(request()),
          $service->listForEvent($event),
        ),
      ],
      message: 'Event staff retrieved.',
    );
  }

  public function store(Request $request, Event $event, EventStaffAssignmentService $service): JsonResponse
  {
    $this->authorize('permission', 'events.manage');
    $this->authorize('view', $event);

    $validated = $request->validate([
      'user_id' => ['required', 'string'],
      'staff_role' => ['nullable', 'string', 'max:40', Rule::in(\App\Modules\Events\Enums\EventStaffRole::acceptedValues())],
      'department' => ['nullable', 'string', 'max:80'],
    ]);

    $user = User::query()->where('uuid', $validated['user_id'])->firstOrFail();
    $assignment = $service->assign($event, $user, $request->user(), $validated);

    return $this->responder->success(
      data: ['assignment' => new EventStaffAssignmentResource($assignment)],
      message: 'Event staff assigned.',
      status: 201,
    );
  }

  public function update(Request $request, EventStaffAssignment $assignment, EventStaffAssignmentService $service): JsonResponse
  {
    $this->authorize('permission', 'events.manage');
    $assignment->loadMissing('event');
    $this->authorize('view', $assignment->event);

    $validated = $request->validate([
      'staff_role' => ['nullable', 'string', 'max:40', Rule::in(\App\Modules\Events\Enums\EventStaffRole::acceptedValues())],
      'department' => ['nullable', 'string', 'max:80'],
      'is_active' => ['nullable', 'boolean'],
    ]);

    $assignment = $service->update($assignment, $validated, $request->user());

    return $this->responder->success(
      data: ['assignment' => new EventStaffAssignmentResource($assignment)],
      message: 'Event staff assignment updated.',
    );
  }

  public function destroy(EventStaffAssignment $assignment, EventStaffAssignmentService $service, Request $request): JsonResponse
  {
    $this->authorize('permission', 'events.manage');
    $assignment->loadMissing('event');
    $this->authorize('view', $assignment->event);

    $assignment = $service->deactivate($assignment, $request->user());

    return $this->responder->success(
      data: ['assignment' => new EventStaffAssignmentResource($assignment)],
      message: 'Event staff assignment deactivated.',
    );
  }
}
