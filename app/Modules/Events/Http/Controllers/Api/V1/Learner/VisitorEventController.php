<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Learner;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventRegistrationResource;
use App\Modules\Events\Services\AttendanceService;
use App\Modules\Events\Services\VisitorEventAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VisitorEventController extends ApiController
{
    public function index(Request $request, VisitorEventAccessService $access, AttendanceService $attendance): JsonResponse
    {
        $this->authorize('permission', 'learner.portal');
        $registrations = $access->registrationsFor($request->user());

        return $this->responder->success(
            data: [
                'registrations' => $registrations->map(function ($registration) use ($attendance, $request) {
                    $summary = $attendance->summarizeRegistration($registration);

                    return array_merge(
                        (new EventRegistrationResource($registration))->resolve($request),
                        [
                            'attendance_summary' => $summary,
                            'membership' => $summary['membership'],
                        ],
                    );
                })->values(),
            ],
            message: 'Visitor event registrations loaded.',
        );
    }

    public function show(string $registration, Request $request, VisitorEventAccessService $access, AttendanceService $attendance): JsonResponse
    {
        $this->authorize('permission', 'learner.portal');
        $model = $access->ownedRegistration($request->user(), $registration);
        $model->load(['event.venue', 'event.country', 'person.country', 'services', 'payments', 'answers.question', 'dayAttendances.day', 'accommodationAllocation.option']);
        $summary = $attendance->summarizeRegistration($model);
        $workspace = \App\Modules\Events\Support\EventParticipantWorkspacePayload::for($model);

        return $this->responder->success(
            data: [
                'registration' => array_merge(
                    (new EventRegistrationResource($model))->resolve($request),
                    ['attendance_summary' => $summary, 'membership' => $summary['membership']],
                    $workspace,
                ),
            ],
            message: 'Visitor event registration loaded.',
        );
    }

    public function claim(Request $request, VisitorEventAccessService $access): JsonResponse
    {
        $this->authorize('permission', 'learner.portal');
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'surname' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $person = $access->claimWithSurname($request->user(), $validated['email'], $validated['surname']);

        return $this->responder->success(
            data: [
                'person' => [
                    'id' => $person->uuid,
                    'person_no' => $person->person_no,
                    'name' => $person->fullName(),
                ],
            ],
            message: 'Participant identity linked to your visitor workspace.',
        );
    }

    public function respondPairing(Request $request, string $pairingId, VisitorEventAccessService $access): JsonResponse
    {
        $this->authorize('permission', 'learner.portal');
        $validated = $request->validate([
            'registration_id' => ['required', 'string'],
            'accept' => ['required', 'boolean'],
            'arrival_date' => ['nullable', 'date'],
            'departure_date' => ['nullable', 'date'],
        ]);
        $registration = $access->ownedRegistration($request->user(), $validated['registration_id']);
        $pairing = \App\Modules\Events\Models\EventAccommodationPairing::query()->where('uuid', $pairingId)->firstOrFail();
        $updated = app(\App\Modules\Events\Services\AccommodationService::class)
            ->respondToPairing($pairing, $registration, (bool) $validated['accept'], $request->user(), $validated);

        return $this->responder->success(
            data: ['pairing' => app(\App\Modules\Events\Services\AccommodationService::class)->pairingPayload($updated)],
            message: 'Pairing response recorded.',
        );
    }
}
