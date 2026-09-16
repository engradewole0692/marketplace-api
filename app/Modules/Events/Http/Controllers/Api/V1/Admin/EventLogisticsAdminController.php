<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Enums\EventStaffDomain;
use App\Modules\Events\Http\Resources\EventTransportOptionResource;
use App\Modules\Events\Http\Resources\EventTransportTripResource;
use App\Modules\Events\Http\Resources\EventTravelRequestResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventTransportOption;
use App\Modules\Events\Models\EventTransportTrip;
use App\Modules\Events\Models\EventTravelRequest;
use App\Modules\Events\Services\TransportService;
use App\Modules\Events\Services\TravelAssistanceService;
use App\Modules\Events\Support\AuthorizesEventStaffDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventLogisticsAdminController extends ApiController
{
    use AuthorizesEventStaffDomain;

    public function transportOptions(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $this->assertEventDomain($event, EventStaffDomain::Logistics);
        $options = $event->transportOptions()->orderBy('sort_order')->get();

        return $this->responder->success(
            data: ['options' => EventTransportOptionResource::collection($options)],
            message: 'Transport options retrieved.',
        );
    }

    public function storeTransportOption(Request $request, Event $event, TransportService $service): JsonResponse
    {
        $this->authorize('view', $event);
        $this->assertEventDomain($event, EventStaffDomain::Logistics);
        $validated = $request->validate($this->transportOptionRules());
        $option = $service->createOption($event, $validated, $request->user());

        return $this->responder->success(
            data: ['option' => new EventTransportOptionResource($option)],
            message: 'Transport option created.',
            status: 201,
        );
    }

    public function updateTransportOption(Request $request, EventTransportOption $option, TransportService $service): JsonResponse
    {
        $this->authorize('view', $option->event);
        $this->assertEventDomain($option->event, EventStaffDomain::Logistics);
        $validated = $request->validate($this->transportOptionRules(true));
        $option = $service->updateOption($option, $validated, $request->user());

        return $this->responder->success(
            data: ['option' => new EventTransportOptionResource($option)],
            message: 'Transport option updated.',
        );
    }

    public function trips(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $this->assertEventDomain($event, EventStaffDomain::Logistics);
        $trips = EventTransportTrip::query()
            ->with(['option', 'registration.person'])
            ->where('event_id', $event->id)
            ->latest('id')
            ->get();

        return $this->responder->success(
            data: ['trips' => EventTransportTripResource::collection($trips)],
            message: 'Transport trips retrieved.',
        );
    }

    public function requestTrip(Request $request, EventRegistration $registration, TransportService $service): JsonResponse
    {
        $this->authorize('view', $registration);
        $this->assertRegistrationDomain($registration, EventStaffDomain::Logistics);
        $validated = $request->validate($this->tripRules());
        $trip = $service->requestTrip($registration, $validated, $request->user());

        return $this->responder->success(
            data: ['trip' => new EventTransportTripResource($trip->load('option'))],
            message: 'Transport trip requested.',
            status: 201,
        );
    }

    public function updateTrip(Request $request, EventTransportTrip $trip, TransportService $service): JsonResponse
    {
        $this->authorize('view', $trip->registration);
        $this->assertRegistrationDomain($trip->registration, EventStaffDomain::Logistics);
        $validated = $request->validate([
            'status' => ['nullable', 'in:requested,confirmed,assigned,in_progress,completed,cancelled'],
            'assigned_vehicle' => ['nullable', 'string', 'max:160'],
            'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'trip_date' => ['nullable', 'date'],
            'trip_time' => ['nullable', 'string', 'max:20'],
        ]);
        $trip = $service->updateTrip($trip, $validated, $request->user());

        return $this->responder->success(
            data: ['trip' => new EventTransportTripResource($trip->load('option'))],
            message: 'Transport trip updated.',
        );
    }

    public function travelRequests(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $this->assertEventDomain($event, EventStaffDomain::Travel);
        $rows = EventTravelRequest::query()
            ->with('registration.person')
            ->where('event_id', $event->id)
            ->latest('id')
            ->get();

        return $this->responder->success(
            data: ['requests' => EventTravelRequestResource::collection($rows)],
            message: 'Travel requests retrieved.',
        );
    }

    public function storeTravel(Request $request, EventRegistration $registration, TravelAssistanceService $service): JsonResponse
    {
        $this->authorize('view', $registration);
        $this->assertRegistrationDomain($registration, EventStaffDomain::Travel);
        $validated = $request->validate($this->travelRules());
        $travel = $service->request($registration, $validated, $request->user());

        return $this->responder->success(
            data: ['travel' => new EventTravelRequestResource($travel)],
            message: 'Travel assistance requested.',
            status: 201,
        );
    }

    public function updateTravel(Request $request, EventTravelRequest $travel, TravelAssistanceService $service): JsonResponse
    {
        $this->authorize('view', $travel->registration);
        $this->assertRegistrationDomain($travel->registration, EventStaffDomain::Travel);
        $validated = $request->validate([
            'status' => ['nullable', 'in:requested,under_review,quote_provided,awaiting_payment,booking_in_progress,booked,cancelled,completed'],
            'quote_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'origin' => ['nullable', 'string', 'max:160'],
            'destination' => ['nullable', 'string', 'max:160'],
            'airline_preference' => ['nullable', 'string', 'max:120'],
            'travel_class' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'quote_currency' => ['nullable', 'string', 'size:3'],
        ]);
        if (! empty($validated['quote_currency']) && empty($validated['currency'])) {
            $validated['currency'] = $validated['quote_currency'];
        }
        $travel = $service->update($travel, $validated, $request->user());

        return $this->responder->success(
            data: ['travel' => new EventTravelRequestResource($travel)],
            message: 'Travel assistance updated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptionRules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'route' => ['nullable', 'string', 'max:160'],
            'public_route_key' => ['nullable', 'in:airport_to_accommodation,hotel_to_venue'],
            'origin' => ['nullable', 'string', 'max:160'],
            'destination' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['nullable', 'in:per_trip,per_passenger,per_vehicle,per_day,custom'],
            'currency' => ['nullable', 'string', 'size:3'],
            'service_date' => ['nullable', 'date'],
            'time_windows' => ['nullable', 'array'],
            'vehicle_type' => ['nullable', 'string', 'max:80'],
            'vehicle_name' => ['nullable', 'string', 'max:120'],
            'make_model' => ['nullable', 'string', 'max:160'],
            'passenger_capacity' => ['nullable', 'integer', 'min:1'],
            'luggage_capacity' => ['nullable', 'integer', 'min:0'],
            'image_media_ids' => ['nullable', 'array'],
            'vehicle_details' => ['nullable', 'string'],
            'pickup_instructions' => ['nullable', 'string'],
            'dropoff_instructions' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tripRules(): array
    {
        return [
            'option_id' => ['nullable', 'string'],
            'route' => ['nullable', 'string', 'max:160'],
            'public_route_key' => ['nullable', 'in:airport_to_accommodation,hotel_to_venue'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'trip_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'trip_time' => ['nullable', 'string', 'max:20'],
            'time' => ['nullable', 'string', 'max:20'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:50'],
            'luggage' => ['nullable', 'string', 'max:120'],
            'special_requirements' => ['nullable', 'string'],
            'flight_info' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function travelRules(): array
    {
        return [
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
        ];
    }
}
