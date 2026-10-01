<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventRegistrationResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EventRegistrationResetAdminController extends ApiController
{
    public function status(Event $event, EventRegistrationResetService $service): JsonResponse
    {
        $this->authorize('resetRegistrations', $event);

        return $this->responder->success(
            data: $service->status($event),
            message: 'Reset status retrieved.',
        );
    }

    public function resetRegistrations(Request $request, Event $event, EventRegistrationResetService $service): JsonResponse
    {
        $this->authorize('resetRegistrations', $event);
        $this->assertConfirmed($request, 'RESET REGISTRATIONS');

        $result = $service->resetRegistrations($event, $request->user());

        return $this->responder->success(
            data: [
                'snapshot_id' => $result['snapshot']->uuid,
                'reset_type' => EventRegistrationResetService::TYPE_REGISTRATIONS,
                'affected_counts' => $result['counts'],
                'can_undo' => $result['snapshot']->canRestore(),
                'expires_at' => $result['snapshot']->expires_at?->toIso8601String(),
            ],
            message: 'Event registration data reset.',
        );
    }

    public function undoRegistrations(Request $request, Event $event, EventRegistrationResetService $service): JsonResponse
    {
        $this->authorize('resetRegistrations', $event);
        $this->assertConfirmed($request, 'UNDO RESET');

        $result = $service->undo($event, $request->user(), EventRegistrationResetService::TYPE_REGISTRATIONS);

        return $this->responder->success(
            data: [
                'snapshot_id' => $result['snapshot']->uuid,
                'restored' => $result['restored'],
            ],
            message: 'Event registration data restored.',
        );
    }

    public function resetFormConfiguration(Request $request, Event $event, EventRegistrationResetService $service): JsonResponse
    {
        $this->authorize('resetRegistrations', $event);
        $this->assertConfirmed($request, 'RESET FORM CONFIGURATION');

        $result = $service->resetFormConfiguration($event, $request->user());

        return $this->responder->success(
            data: [
                'snapshot_id' => $result['snapshot']->uuid,
                'reset_type' => EventRegistrationResetService::TYPE_FORM_CONFIGURATION,
                'affected_counts' => $result['counts'],
                'can_undo' => $result['snapshot']->canRestore(),
                'expires_at' => $result['snapshot']->expires_at?->toIso8601String(),
            ],
            message: 'Event registration form configuration reset.',
        );
    }

    public function undoFormConfiguration(Request $request, Event $event, EventRegistrationResetService $service): JsonResponse
    {
        $this->authorize('resetRegistrations', $event);
        $this->assertConfirmed($request, 'UNDO RESET');

        $result = $service->undo($event, $request->user(), EventRegistrationResetService::TYPE_FORM_CONFIGURATION);

        return $this->responder->success(
            data: [
                'snapshot_id' => $result['snapshot']->uuid,
                'restored' => $result['restored'],
            ],
            message: 'Event registration form configuration restored.',
        );
    }

    private function assertConfirmed(Request $request, string $phrase): void
    {
        $confirmed = $request->boolean('confirm');
        $typed = trim((string) $request->input('confirmation', ''));
        if (! $confirmed || strcasecmp($typed, $phrase) !== 0) {
            throw ValidationException::withMessages([
                'confirmation' => ['Type '.$phrase.' and confirm to continue. This action is destructive.'],
            ]);
        }
    }
}
