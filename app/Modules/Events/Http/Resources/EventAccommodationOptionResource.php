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
        $occupancy = $this->occupancyValue();
        $personNight = $this->perPersonNight($occupancy);

        return [
            'id' => $this->uuid,
            'event_id' => $this->event?->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'address' => $this->address,
            'distance_from_venue' => $this->distance_from_venue,
            'room_type' => $this->room_type,
            'amenities' => $this->amenities ?? [],
            'images' => $this->imageUrls(),
            'image_media_ids' => $this->image_media_ids ?? [],
            'occupancy_type' => $occupancy,
            'capacity' => $this->capacity,
            'unit_count' => $this->unit_count,
            'total_spaces' => $this->totalSpaces(),
            'price' => $this->price !== null ? (float) $this->price : 0,
            'price_per_night' => $this->roomNightPrice($occupancy),
            'private_price' => $this->private_price !== null ? (float) $this->private_price : null,
            'shared_price' => $this->shared_price !== null ? (float) $this->shared_price : null,
            'person_price_per_night' => $personNight,
            'price_basis' => $this->price_basis,
            'currency' => $this->currency,
            'min_nights' => $this->min_nights,
            'max_nights' => $this->max_nights,
            'check_in_info' => $this->check_in_info,
            'check_out_info' => $this->check_out_info,
            'notes' => $this->notes,
            'require_full_occupancy' => (bool) $this->require_full_occupancy,
            'is_active' => $this->is_active !== false,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
        ];
    }
}
