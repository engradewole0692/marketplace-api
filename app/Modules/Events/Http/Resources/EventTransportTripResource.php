<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventTransportTrip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventTransportTrip */
final class EventTransportTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_id' => $this->event?->uuid,
            'registration_id' => $this->registration?->uuid,
            'option_id' => $this->option?->uuid,
            'option' => $this->whenLoaded('option', fn () => new EventTransportOptionResource($this->option)),
            'route' => $this->route,
            'pickup_location' => $this->pickup_location,
            'dropoff_location' => $this->dropoff_location,
            'trip_date' => $this->trip_date?->toDateString(),
            'trip_time' => $this->trip_time,
            'passengers' => $this->passengers,
            'luggage' => $this->luggage,
            'special_requirements' => $this->special_requirements,
            'flight_info' => $this->flight_info,
            'amount' => $this->amount !== null ? (float) $this->amount : 0,
            'currency' => $this->currency,
            'status' => $this->status,
            'assigned_vehicle' => $this->assigned_vehicle,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
        ];
    }
}
