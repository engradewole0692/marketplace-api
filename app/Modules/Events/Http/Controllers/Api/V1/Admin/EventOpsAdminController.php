<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventCheckInResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\AttendanceService;
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

    public function scanPreview(Request $request, AttendanceService $attendanceService): JsonResponse
    {
        $this->authorize('permission', 'attendance.manage');
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'event_id' => ['nullable', 'string'],
            'event_day_id' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $checkIn = $attendanceService->checkInByToken(
            $validated['token'],
            [
                'force' => (bool) ($validated['force'] ?? false),
                'notes' => $validated['notes'] ?? null,
                'event_id' => $validated['event_id'] ?? null,
                'event_day_id' => $validated['event_day_id'] ?? null,
            ],
            $request->user(),
        );

        $registration = $checkIn->registration()->with(['person.member', 'event.days', 'dayAttendances.day'])->first();
        $summary = $registration ? $attendanceService->summarizeRegistration($registration) : null;

        return $this->responder->success(
            data: [
                'check_in' => new EventCheckInResource($checkIn),
                'participant' => [
                    'name' => $registration?->contactName(),
                    'registration_number' => $registration?->registration_number,
                    'person_no' => $registration?->person?->person_no,
                    'membership' => $summary['membership'] ?? null,
                ],
                'attendance' => $summary,
            ],
            message: 'Check-in recorded.',
            status: 201,
        );
    }
}
