<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventTransportTripResource;
use App\Modules\Events\Http\Resources\EventTravelRequestResource;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventTravelRequest;
use App\Modules\Events\Services\AccommodationService;
use App\Modules\Events\Services\TransportService;
use App\Modules\Events\Services\TravelAssistanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventParticipantServiceController extends ApiController
{
    public function requestAccommodation(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $registration->loadMissing('event');
        if ($registration->event && ! $registration->event->accommodation_enabled) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'accommodation' => ['Accommodation is not enabled for this event.'],
            ]);
        }
        $validated = $request->validate([
            'option_id' => ['required', 'string'],
            'occupancy_type' => ['nullable', 'in:private,shared'],
            'arrival_date' => ['nullable', 'date'],
            'departure_date' => ['nullable', 'date', 'after:arrival_date'],
            'share_with' => ['nullable', 'array'],
            'share_with.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $service->requestForRegistration($registration, $validated, $request->user());
        $pairings = $service->pairingsForRegistration($registration);

        return $this->responder->success(
            data: [
                'service' => $result,
                'pairings' => $pairings->map(fn ($pairing) => $service->pairingPayload($pairing))->values(),
            ],
            message: 'Accommodation requested.',
        );
    }

    public function searchParticipants(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return $this->responder->success(
            data: ['participants' => $service->searchParticipants($registration, $validated['q'])],
            message: 'Participants retrieved.',
        );
    }

    public function invite(Request $request, EventRegistration $registration, EventAccommodationPairing $pairing, AccommodationService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $validated = $request->validate([
            'registration_ids' => ['required', 'array', 'min:1'],
            'registration_ids.*' => ['string'],
        ]);
        $updated = $service->inviteToPairing($pairing, $registration, $validated['registration_ids'], $request->user());

        return $this->responder->success(
            data: ['pairing' => $service->pairingPayload($updated)],
            message: 'Invitations sent.',
        );
    }

    public function respondPairing(Request $request, EventRegistration $registration, EventAccommodationPairing $pairing, AccommodationService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $validated = $request->validate(['accept' => ['required', 'boolean']]);
        $updated = $service->respondToPairing($pairing, $registration, (bool) $validated['accept'], $request->user());

        return $this->responder->success(
            data: ['pairing' => $service->pairingPayload($updated)],
            message: $validated['accept'] ? 'Sharing request accepted.' : 'Sharing request declined.',
        );
    }

    public function requestTrip(Request $request, EventRegistration $registration, TransportService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $registration->loadMissing('event');
        if ($registration->event && ! $registration->event->transport_enabled) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'transport' => ['Transportation is not enabled for this event.'],
            ]);
        }
        $validated = $request->validate([
            'option_id' => ['nullable', 'string'],
            'route' => ['nullable', 'string', 'max:160'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'trip_date' => ['nullable', 'date'],
            'trip_time' => ['nullable', 'string', 'max:20'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:50'],
            'luggage' => ['nullable', 'string', 'max:120'],
            'special_requirements' => ['nullable', 'string'],
            'flight_info' => ['nullable', 'array'],
        ]);
        $trip = $service->requestTrip($registration, $validated, $request->user());

        return $this->responder->success(
            data: ['trip' => new EventTransportTripResource($trip->load('option'))],
            message: 'Transport trip requested.',
            status: 201,
        );
    }

    public function requestTravel(Request $request, EventRegistration $registration, TravelAssistanceService $service): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $registration->loadMissing('event');
        if ($registration->event && ! $registration->event->travel_assistance_enabled) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'travel' => ['Travel assistance is not enabled for this event.'],
            ]);
        }
        $validated = $request->validate([
            'trip_type' => ['nullable', 'in:one_way,return,multi_city'],
            'origin' => ['nullable', 'string', 'max:160'],
            'destination' => ['nullable', 'string', 'max:160'],
            'departure_date' => ['nullable', 'date'],
            'preferred_departure_time' => ['nullable', 'string', 'max:40'],
            'return_date' => ['nullable', 'date'],
            'preferred_return_time' => ['nullable', 'string', 'max:40'],
            'airline_preference' => ['nullable', 'string', 'max:120'],
            'travel_class' => ['nullable', 'in:economy,premium_economy,business,first,other'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'passenger_names' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);
        $travel = $service->request($registration, $validated, $request->user());

        return $this->responder->success(
            data: ['travel' => new EventTravelRequestResource($travel)],
            message: 'Travel assistance requested.',
            status: 201,
        );
    }

    public function showTravel(Request $request, EventRegistration $registration): JsonResponse
    {
        $this->assertOwns($request, $registration);
        $travel = EventTravelRequest::query()->where('registration_id', $registration->id)->first();

        return $this->responder->success(
            data: ['travel' => $travel ? new EventTravelRequestResource($travel) : null],
            message: 'Travel assistance retrieved.',
        );
    }

    private function assertOwns(Request $request, EventRegistration $registration): void
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthorizationException;
        }
        $registration->loadMissing(['person', 'member']);
        if ((int) $registration->person?->user_id === (int) $user->id) {
            return;
        }
        if ((int) $registration->member?->user_id === (int) $user->id) {
            return;
        }
        if ($user->hasPermission('registrations.manage') || $user->hasPermission('events.manage')) {
            $this->authorize('update', $registration);

            return;
        }

        throw new AuthorizationException;
    }
}
