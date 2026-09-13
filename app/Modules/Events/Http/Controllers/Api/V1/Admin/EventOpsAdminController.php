<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\ScanCheckInRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\EventOperationalProfileService;
use App\Modules\Events\Services\EventOpsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventOpsAdminController extends ApiController
{
    public function dashboard(Event $event, Request $request, EventOpsDashboardService $service): JsonResponse
    {
        $this->authorize('view', $event);

        return $this->responder->success(
            data: $service->snapshot($event, $request->query('event_day_id')),
            message: 'Event operations snapshot loaded.',
        );
    }

    public function attendanceReport(Event $event, Request $request, EventOpsDashboardService $service): JsonResponse
    {
        $this->authorize('view', $event);

        return $this->responder->success(
            data: $service->attendanceReport($event, $request->query()),
            message: 'Attendance report loaded.',
        );
    }

    public function scanPreview(ScanCheckInRequest $request, AttendanceService $attendanceService, EventOperationalProfileService $profile): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasAnyPermission(['attendance.manage', 'events.manage', 'events.staff']),
            403,
        );

        $registration = $attendanceService->lookupByToken(
            $request->validated('token'),
            [
                'event_id' => $request->validated('event_id'),
                'event_day_id' => $request->validated('event_day_id'),
            ],
            $user,
        );

        return $this->responder->success(
            data: [
                'participant' => $profile->forRegistration($registration, [
                    'event_day_id' => $request->validated('event_day_id'),
                ], $user),
            ],
            message: 'Participant identified.',
        );
    }
}
