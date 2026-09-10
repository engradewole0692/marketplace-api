<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventAccommodationOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventAccommodationOption */
final class EventAccommodationOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_id' => $this->event?->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'amenities' => $this->amenities ?? [],
            'images' => $this->imageUrls(),
            'occupancy_type' => $this->occupancy_type instanceof \BackedEnum
                ? $this->occupancy_type->value
                : $this->occupancy_type,
            'capacity' => $this->capacity,
            'unit_count' => $this->unit_count,
            'total_spaces' => $this->totalSpaces(),
            'price' => $this->price !== null ? (float) $this->price : 0,
            'price_basis' => $this->price_basis,
            'currency' => $this->currency,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
        ];
    }
}
