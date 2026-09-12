<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventAccommodationOptionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventAccommodationOption;
use App\Modules\Events\Models\EventAccommodationPairing;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\AccommodationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventAccommodationAdminController extends ApiController
{
    public function index(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $options = $event->accommodationOptions()->orderBy('sort_order')->get();
        $service = app(AccommodationService::class);

        return $this->responder->success(
            data: [
                'options' => $options->map(function (EventAccommodationOption $option) use ($service) {
                    return (new EventAccommodationOptionResource($option))->resolve(request()) + [
                        'inventory' => $service->inventory($option),
                    ];
                }),
            ],
            message: 'Accommodation options retrieved.',
        );
    }

    public function store(Request $request, Event $event, AccommodationService $service): JsonResponse
    {
        $this->authorize('update', $event);
        $validated = $request->validate($this->rules());
        $option = $service->createOption($event, $validated, $request->user());

        return $this->responder->success(
            data: ['option' => new EventAccommodationOptionResource($option)],
            message: 'Accommodation option created.',
            status: 201,
        );
    }

    public function update(Request $request, EventAccommodationOption $option, AccommodationService $service): JsonResponse
    {
        $this->authorize('update', $option->event);
        $validated = $request->validate($this->rules(true));
        $option = $service->updateOption($option, $validated);

        return $this->responder->success(
            data: ['option' => new EventAccommodationOptionResource($option)],
            message: 'Accommodation option updated.',
        );
    }

    public function allocate(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->authorize('update', $registration);
        $validated = $request->validate([
            'option_id' => ['required', 'string'],
            'spaces' => ['nullable', 'integer', 'min:1'],
            'pairing_id' => ['nullable', 'string'],
        ]);
        $option = EventAccommodationOption::query()
            ->where('event_id', $registration->event_id)
            ->where('uuid', $validated['option_id'])
            ->firstOrFail();
        $pairingId = null;
        if (! empty($validated['pairing_id'])) {
            $pairingId = EventAccommodationPairing::query()->where('uuid', $validated['pairing_id'])->value('id');
        }
        $allocation = $service->allocate($registration, $option, $request->user(), (int) ($validated['spaces'] ?? 1), $pairingId);

        return $this->responder->success(
            data: ['allocation' => $allocation],
            message: 'Accommodation allocated.',
            status: 201,
        );
    }

    public function confirm(EventAccommodationAllocation $allocation, AccommodationService $service, Request $request): JsonResponse
    {
        $this->authorize('update', $allocation->registration);
        $allocation = $service->confirm($allocation, $request->user());

        return $this->responder->success(
            data: ['allocation' => $allocation->load('option')],
            message: 'Accommodation confirmed.',
        );
    }

    public function requestPairing(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->authorize('update', $registration);
        $validated = $request->validate([
            'registration_ids' => ['required', 'array', 'min:1'],
            'registration_ids.*' => ['string'],
            'option_id' => ['nullable', 'string'],
            'check_in_date' => ['nullable', 'date'],
            'check_out_date' => ['nullable', 'date', 'after:check_in_date'],
        ]);
        $pairing = $service->requestPairing(
            $registration,
            $validated['registration_ids'],
            $validated['option_id'] ?? null,
            $request->user(),
            [
                'check_in_date' => $validated['check_in_date'] ?? null,
                'check_out_date' => $validated['check_out_date'] ?? null,
            ],
        );

        return $this->responder->success(
            data: ['pairing' => $service->pairingPayload($pairing)],
            message: 'Pairing requested. Waiting for confirmation.',
            status: 201,
        );
    }

    public function respondPairing(Request $request, EventAccommodationPairing $pairing, AccommodationService $service): JsonResponse
    {
        $validated = $request->validate([
            'registration_id' => ['required', 'string'],
            'accept' => ['required', 'boolean'],
        ]);
        $registration = EventRegistration::query()->where('uuid', $validated['registration_id'])->firstOrFail();
        $this->authorize('update', $registration);
        $pairing = $service->respondToPairing($pairing, $registration, (bool) $validated['accept'], $request->user());

        return $this->responder->success(
            data: ['pairing' => $service->pairingPayload($pairing)],
            message: $validated['accept'] ? 'Pairing response recorded.' : 'Pairing declined.',
        );
    }

    public function dashboard(Event $event, AccommodationService $service): JsonResponse
    {
        $this->authorize('view', $event);

        return $this->responder->success(
            data: $service->dashboard($event),
            message: 'Accommodation dashboard retrieved.',
        );
    }

    public function requestForRegistration(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->authorize('update', $registration);
        $validated = $request->validate([
            'option_id' => ['nullable', 'string'],
            'occupancy_type' => ['nullable', 'in:private,shared'],
            'arrival_date' => ['nullable', 'date'],
            'departure_date' => ['nullable', 'date', 'after:arrival_date'],
            'share_with' => ['nullable', 'array'],
            'share_with.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $service->requestForRegistration($registration, $validated, $request->user());

        return $this->responder->success(
            data: ['service' => $result],
            message: 'Accommodation requested.',
        );
    }

    public function searchParticipants(Request $request, EventRegistration $registration, AccommodationService $service): JsonResponse
    {
        $this->authorize('view', $registration);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return $this->responder->success(
            data: ['participants' => $service->searchParticipants($registration, $validated['q'])],
            message: 'Participants retrieved.',
        );
    }

    public function invite(Request $request, EventAccommodationPairing $pairing, AccommodationService $service): JsonResponse
    {
        $validated = $request->validate([
            'registration_id' => ['required', 'string'],
            'registration_ids' => ['required', 'array', 'min:1'],
            'registration_ids.*' => ['string'],
        ]);
        $registration = EventRegistration::query()->where('uuid', $validated['registration_id'])->firstOrFail();
        $this->authorize('update', $registration);
        $updated = $service->inviteToPairing($pairing, $registration, $validated['registration_ids'], $request->user());

        return $this->responder->success(
            data: ['pairing' => $service->pairingPayload($updated)],
            message: 'Invitations sent.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'amenities' => ['nullable', 'array'],
            'image_media_ids' => ['nullable', 'array'],
            'occupancy_type' => ['nullable', 'in:private,shared'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'unit_count' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'address' => ['nullable', 'string', 'max:255'],
            'distance_from_venue' => ['nullable', 'string', 'max:120'],
            'room_type' => ['nullable', 'string', 'max:80'],
            'check_in_info' => ['nullable', 'string'],
            'check_out_info' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:30'],
            'max_nights' => ['nullable', 'integer', 'min:1', 'max:60'],
            'price_per_night' => ['nullable', 'numeric', 'min:0'],
            'private_price' => ['nullable', 'numeric', 'min:0'],
            'shared_price' => ['nullable', 'numeric', 'min:0'],
            'require_full_occupancy' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
