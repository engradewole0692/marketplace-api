<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Http\Requests\CheckInRequest;
use App\Modules\Events\Http\Requests\UpdateRegistrationStatusRequest;
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

  public function show(EventRegistration $registration): JsonResponse
  {
    $this->authorize('view', $registration);

    return $this->responder->success(
      data: ['registration' => new EventRegistrationResource($registration->load(['event', 'member', 'answers.question', 'checkInToken']))],
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
