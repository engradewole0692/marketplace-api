<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Cms\Http\Resources\CmsCountryResource;
use App\Modules\Events\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Venue */
final class VenueResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'description' => $this->description,
      'address_line_1' => $this->address_line_1,
      'address_line_2' => $this->address_line_2,
      'city' => $this->city,
      'country' => $this->whenLoaded('country', fn () => new CmsCountryResource($this->country)),
      'region_id' => $this->region_id,
      'postal_code' => $this->postal_code,
      'latitude' => $this->latitude,
      'longitude' => $this->longitude,
      'capacity' => $this->capacity,
      'contact_name' => $this->contact_name,
      'contact_email' => $this->contact_email,
      'contact_phone' => $this->contact_phone,
      'status' => $this->status,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
