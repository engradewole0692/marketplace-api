<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsCountry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsCountry */
final class CmsCountryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'code' => $this->code,
      'region' => $this->region,
      'flag_emoji' => $this->flag_emoji,
      'latitude' => $this->latitude,
      'longitude' => $this->longitude,
      'launched_year' => $this->launched_year,
      'summary' => $this->summary,
      'content' => $this->content,
      'leaders' => CmsLeadershipResource::collection($this->whenLoaded('leaders')),
      'primary_leader' => $this->whenLoaded('primaryLeader', fn () => new CmsLeadershipResource($this->primaryLeader)),
      'phone' => $this->phone,
      'whatsapp_number' => $this->whatsapp_number,
      'office_address' => $this->office_address,
      'office_hours' => $this->office_hours,
      'hero_media_id' => $this->heroMedia?->uuid,
      'image_url' => $this->heroMedia?->url(),
      'is_active' => $this->is_active,
      'sort_order' => $this->sort_order,
    ];
  }
}
