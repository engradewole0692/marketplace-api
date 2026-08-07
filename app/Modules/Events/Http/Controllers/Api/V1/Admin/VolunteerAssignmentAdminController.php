<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreVolunteerAssignmentRequest;
use App\Modules\Events\Http\Requests\UpdateVolunteerAssignmentRequest;
use App\Modules\Events\Http\Resources\EventVolunteerAssignmentResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Services\NotificationService;
use App\Modules\Events\Services\VolunteerService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VolunteerAssignmentAdminController extends ApiController
{
  public function index(Request $request, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->listAssignments($request->query()),
        EventVolunteerAssignmentResource::class,
      ),
      message: 'Volunteer assignments retrieved.',
    );
  }

  public function interested(Request $request, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    $eventId = (int) $request->query('event_id');
    if ($eventId === 0) {
      abort(422, 'event_id is required.');
    }

    return $this->responder->success(
      data: ['registrations' => $service->interestedRegistrations($eventId)],
      message: 'Interested registrations retrieved.',
    );
  }

  public function store(
    StoreVolunteerAssignmentRequest $request,
    VolunteerService $service,
    NotificationService $notificationService,
  ): JsonResponse {
    $this->authorize('permission', 'volunteers.manage');

    $registration = EventRegistration::query()->findOrFail((int) $request->validated('registration_id'));
    $assignment = $service->assign($registration, $request->validated(), $request->user());

    if ($assignment->role !== null) {
      $notificationService->volunteerAssigned($registration, (string) $assignment->role->name);
    }

    return $this->responder->success(
      data: ['assignment' => new EventVolunteerAssignmentResource($assignment)],
      message: 'Volunteer assignment created.',
      status: 201,
    );
  }

  public function update(
    UpdateVolunteerAssignmentRequest $request,
    EventVolunteerAssignment $assignment,
    VolunteerService $service,
  ): JsonResponse {
    $this->authorize('permission', 'volunteers.manage');

    $assignment = $service->updateAssignment($assignment, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['assignment' => new EventVolunteerAssignmentResource($assignment)],
      message: 'Volunteer assignment updated.',
    );
  }

  public function destroy(EventVolunteerAssignment $assignment, VolunteerService $service): JsonResponse
  {
    $this->authorize('permission', 'volunteers.manage');

    $service->deleteAssignment($assignment);

    return $this->responder->success(data: null, message: 'Volunteer assignment removed.');
  }
}
