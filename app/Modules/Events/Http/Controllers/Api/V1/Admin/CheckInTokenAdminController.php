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

  public function scanIn(ScanCheckInRequest $request, AttendanceService $service): JsonResponse
  {
    $this->authorize('permission', 'attendance.manage');

    $checkIn = $service->checkInByToken(
      $request->validated('token'),
      [
        'force' => (bool) $request->validated('force', false),
        'notes' => $request->validated('notes'),
        'event_session_id' => $request->validated('event_session_id'),
      ],
      $request->user(),
    );

    return $this->responder->success(
      data: ['check_in' => new EventCheckInResource($checkIn)],
      message: 'Check-in recorded.',
      status: 201,
    );
  }

  public function scanOut(ScanCheckInRequest $request, AttendanceService $service): JsonResponse
  {
    $this->authorize('permission', 'attendance.manage');

    $history = $service->checkOutByToken(
      $request->validated('token'),
      ['notes' => $request->validated('notes')],
      $request->user(),
    );

    return $this->responder->success(
      data: ['attendance' => new EventAttendanceHistoryResource($history)],
      message: 'Check-out recorded.',
    );
  }
}
