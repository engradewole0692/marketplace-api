<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventTravelRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventTravelRequest */
final class EventTravelRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_id' => $this->event?->uuid,
            'registration_id' => $this->registration?->uuid,
            'trip_type' => $this->trip_type,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_date' => $this->departure_date?->toDateString(),
            'preferred_departure_time' => $this->preferred_departure_time,
            'return_date' => $this->return_date?->toDateString(),
            'preferred_return_time' => $this->preferred_return_time,
            'airline_preference' => $this->airline_preference,
            'travel_class' => $this->travel_class,
            'passengers' => $this->passengers,
            'passenger_names' => $this->passenger_names ?? [],
            'notes' => $this->notes,
            'quote_amount' => $this->quote_amount !== null ? (float) $this->quote_amount : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'booked_at' => $this->booked_at?->toIso8601String(),
        ];
    }
}
