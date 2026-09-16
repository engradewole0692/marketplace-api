<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\ScanCheckInRequest;
use App\Modules\Events\Http\Resources\EventAttendanceHistoryResource;
use App\Modules\Events\Http\Resources\EventCheckInResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\CheckInTokenService;
use App\Modules\Events\Services\EventOperationalProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckInTokenAdminController extends ApiController
{
  public function issue(EventRegistration $registration, Request $request, CheckInTokenService $service): JsonResponse
  {
    $this->authorize('checkIn', $registration);

    $result = $service->regenerate($registration, null, $request->user());

    return $this->responder->success(
      data: [
        'token' => $result['token'],
        'registration_id' => $registration->uuid,
        'expires_at' => $result['model']->expires_at?->toIso8601String(),
      ],
      message: 'Check-in token issued.',
      status: 201,
    );
  }

  public function lookup(ScanCheckInRequest $request, AttendanceService $service, EventOperationalProfileService $profile): JsonResponse
  {
    $this->assertOpsPermission($request);

    $registration = $service->lookupByToken(
      $request->validated('token'),
      [
        'event_id' => $request->validated('event_id'),
        'event_day_id' => $request->validated('event_day_id'),
        'event_session_id' => $request->validated('event_session_id'),
      ],
      $request->user(),
    );

    return $this->responder->success(
      data: [
        'participant' => $profile->forRegistration($registration, [
          'event_day_id' => $request->validated('event_day_id'),
        ], $request->user()),
      ],
      message: 'Participant identified.',
    );
  }

  public function scanIn(ScanCheckInRequest $request, AttendanceService $service, EventOperationalProfileService $profile): JsonResponse
  {
    $this->assertOpsPermission($request);

    $checkIn = $service->checkInByToken(
      $request->validated('token'),
      [
        'force' => (bool) $request->validated('force', false),
        'notes' => $request->validated('notes'),
        'event_session_id' => $request->validated('event_session_id'),
        'event_day_id' => $request->validated('event_day_id'),
        'event_id' => $request->validated('event_id'),
        'seating_area' => $request->validated('seating_area'),
        'capacity_override' => (bool) $request->validated('capacity_override', false),
        'override_reason' => $request->validated('override_reason'),
      ],
      $request->user(),
    );

    $registration = $checkIn->registration()->with(['person.member', 'event', 'dayAttendances.day'])->first();
    $profilePayload = $registration
      ? $profile->forRegistration($registration, [
        'event_day_id' => $request->validated('event_day_id'),
      ], $request->user())
      : null;

    return $this->responder->success(
      data: [
        'check_in' => new EventCheckInResource($checkIn->loadMissing(['checkedInBy', 'day', 'event', 'registration.person'])),
        'participant' => $profilePayload === null ? null : array_merge([
          'name' => $profilePayload['identity']['name'] ?? null,
          'registration_number' => $profilePayload['identity']['registration_number'] ?? null,
          'person_no' => $profilePayload['identity']['person_no'] ?? null,
          'membership' => $profilePayload['identity']['membership'] ?? null,
        ], $profilePayload),
        'attendance' => $profilePayload['attendance']['summary'] ?? null,
      ],
      message: 'Check-in recorded.',
      status: 201,
    );
  }

  public function scanOut(ScanCheckInRequest $request, AttendanceService $service, EventOperationalProfileService $profile): JsonResponse
  {
    $this->assertOpsPermission($request);

    $history = $service->checkOutByToken(
      $request->validated('token'),
      [
        'notes' => $request->validated('notes'),
        'event_day_id' => $request->validated('event_day_id'),
        'event_id' => $request->validated('event_id'),
      ],
      $request->user(),
    );

    $registration = $history->registration()->with(['person.member', 'event', 'dayAttendances.day'])->first();

    return $this->responder->success(
      data: [
        'attendance' => new EventAttendanceHistoryResource($history),
        'participant' => $registration
          ? $profile->forRegistration($registration, [
            'event_day_id' => $request->validated('event_day_id'),
          ], $request->user())
          : null,
      ],
      message: 'Check-out recorded.',
    );
  }

  private function assertOpsPermission(Request $request): void
  {
    $user = $request->user();
    abort_unless(
      $user !== null && $user->hasAnyPermission(['attendance.manage', 'events.manage', 'events.staff']),
      403,
    );
  }
}
