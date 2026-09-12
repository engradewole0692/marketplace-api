<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Http\Resources\EventAccommodationOptionResource;
use App\Modules\Events\Http\Resources\EventTransportTripResource;
use App\Modules\Events\Http\Resources\EventTravelRequestResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventTransportTrip;
use App\Modules\Events\Models\EventTravelRequest;
use App\Modules\Events\Services\AccommodationService;

final class EventParticipantWorkspacePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(EventRegistration $registration): array
    {
        $registration->loadMissing(['accommodationAllocation.option', 'payments']);
        $accommodation = app(AccommodationService::class);
        $pairings = $accommodation->pairingsForRegistration($registration)
            ->map(fn ($pairing) => $accommodation->pairingPayload($pairing))
            ->values();
        $trips = EventTransportTrip::query()
            ->with('option')
            ->where('registration_id', $registration->id)
            ->orderBy('trip_date')
            ->orderBy('id')
            ->get();
        $travel = EventTravelRequest::query()->where('registration_id', $registration->id)->first();
        $allocation = $registration->accommodationAllocation;

        return [
            'pairings' => $pairings,
            'transport_trips' => EventTransportTripResource::collection($trips)->resolve(),
            'travel_request' => $travel ? (new EventTravelRequestResource($travel))->resolve() : null,
            'allocation' => $allocation ? [
                'id' => $allocation->uuid,
                'status' => $allocation->status,
                'check_in_date' => $allocation->check_in_date?->toDateString(),
                'check_out_date' => $allocation->check_out_date?->toDateString(),
                'details' => $allocation->details,
                'option' => $allocation->option
                    ? (new EventAccommodationOptionResource($allocation->option))->resolve()
                    : null,
            ] : null,
            'service_payments' => $registration->payments->map(fn (EventRegistrationPayment $payment) => [
                'id' => $payment->uuid,
                'purpose' => $payment->purpose ?? 'registration',
                'amount' => $payment->amount !== null ? (float) $payment->amount : 0,
                'currency' => $payment->currency,
                'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
