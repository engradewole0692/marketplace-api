<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventTransportOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventTransportOption */
final class EventTransportOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_id' => $this->event?->uuid,
            'name' => $this->name,
            'route' => $this->route,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : 0,
            'price_basis' => $this->price_basis,
            'currency' => $this->currency,
            'service_date' => $this->service_date?->toDateString(),
            'time_windows' => $this->time_windows ?? [],
            'vehicle_type' => $this->vehicle_type,
            'vehicle_name' => $this->vehicle_name,
            'make_model' => $this->make_model,
            'passenger_capacity' => $this->passenger_capacity,
            'luggage_capacity' => $this->luggage_capacity,
            'images' => $this->imageUrls(),
            'vehicle_details' => $this->vehicle_details,
            'pickup_instructions' => $this->pickup_instructions,
            'dropoff_instructions' => $this->dropoff_instructions,
            'status' => $this->status,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
