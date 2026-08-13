<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Http\Requests\CheckInRequest;
use App\Modules\Events\Http\Requests\StoreAdminRegistrationRequest;
use App\Modules\Events\Http\Requests\UpdateRegistrationStatusRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\PublicEventAccess;
use App\Modules\Events\Http\Resources\EventAttendanceHistoryResource;
use App\Modules\Events\Http\Resources\EventCheckInResource;
use App\Modules\Events\Http\Resources\EventRegistrationResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\NotificationService;
use App\Modules\Events\Services\RegistrationService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegistrationAdminController extends ApiController
{
  public function index(Request $request, RegistrationService $service): JsonResponse
  {
    $this->authorize('viewAny', EventRegistration::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EventRegistrationResource::class),
      message: 'Registrations retrieved.',
    );
  }

  public function search(Request $request, RegistrationService $service): JsonResponse
  {
    $this->authorize('viewAny', EventRegistration::class);

    $request->validate([
      'q' => ['required', 'string', 'min:2', 'max:120'],
      'event_id' => ['nullable', 'string'],
    ]);

    $eventId = null;
    if ($request->filled('event_id')) {
      $eventId = Event::query()->where('uuid', $request->query('event_id'))->value('id');
      if ($eventId !== null) {
        $eventId = (int) $eventId;
      }
    }

    $results = $service->searchRegistrants((string) $request->query('q'), $eventId ?: null);

    return $this->responder->success(
      data: $results,
      message: 'Registrant search completed.',
    );
  }

  public function store(StoreAdminRegistrationRequest $request, RegistrationService $service, AttendanceService $attendanceService, NotificationService $notificationService): JsonResponse
  {
    $this->authorize('create', EventRegistration::class);

    $event = Event::query()->findOrFail($request->validated('event_id'));
    PublicEventAccess::ensureRegistrationAllowed($event);

    $payload = [
      ...$request->validated(),
      'source' => 'on_site',
      'consent_accepted' => $request->boolean('consent_accepted', true),
    ];

    $result = $service->register($payload, $request->user());

    try {
      $notificationService->sendRegistrationNotifications($result['registration'], $result['created']);
    } catch (\Throwable) {
      // Non-blocking for on-site flow.
    }

    if ($request->boolean('check_in_immediately')) {
      $attendanceService->checkIn(
        $result['registration']->fresh(['event', 'member']),
        ['method' => 'manual'],
        $request->user(),
      );
      $result['registration'] = $result['registration']->fresh(['event', 'member']);
    }

    return $this->responder->success(
      data: ['registration' => new EventRegistrationResource($result['registration'])],
      message: $result['created'] ? 'On-site registration created.' : 'Existing registration updated.',
      status: $result['created'] ? 201 : 200,
    );
  }

  public function show(EventRegistration $registration): JsonResponse
  {
    $this->authorize('view', $registration);

    $registration->load([
      'event',
      'member',
      'answers.question',
      'checkInToken',
      'payments',
      'timelines.actor',
      'auditLogs.actor',
      'statusTransitions.actor',
    ]);

    return $this->responder->success(
      data: ['registration' => new EventRegistrationResource($registration)],
      message: 'Registration retrieved.',
    );
  }

  public function updateStatus(UpdateRegistrationStatusRequest $request, EventRegistration $registration, RegistrationService $service): JsonResponse
  {
    $this->authorize('update', $registration);

    $status = $request->validated('status');
    $registration = $service->transition(
      $registration,
      $status instanceof \BackedEnum ? $status : \App\Modules\Events\Enums\RegistrationStatus::from((string) $status),
      $request->user(),
      $request->validated('reason'),
    );

    return $this->responder->success(
      data: ['registration' => new EventRegistrationResource($registration)],
      message: 'Registration status updated.',
    );
  }

  public function checkIn(CheckInRequest $request, EventRegistration $registration, AttendanceService $service): JsonResponse
  {
    $this->authorize('checkIn', $registration);

    $checkIn = $service->checkIn($registration, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['check_in' => new EventCheckInResource($checkIn)],
      message: 'Registration checked in.',
      status: 201,
    );
  }

  public function checkOut(CheckInRequest $request, EventRegistration $registration, AttendanceService $service): JsonResponse
  {
    $this->authorize('checkIn', $registration);

    $history = $service->checkOut($registration, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['attendance' => new EventAttendanceHistoryResource($history)],
      message: 'Registration checked out.',
    );
  }

  public function destroy(EventRegistration $registration, RegistrationService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $registration);

    $service->delete($registration, $request->user());

    return $this->responder->success(data: null, message: 'Registration deleted.');
  }
}
