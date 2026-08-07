<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsPartner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsPartner */
final class CmsPartnerResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'country_id' => $this->country?->uuid,
      'country_name' => $this->country?->name,
      'tier' => $this->tier,
      'website_url' => $this->website_url,
      'donation_url' => $this->donation_url,
      'description' => $this->description,
      'logo_media_id' => $this->logoMedia?->uuid,
      'logo_url' => $this->logoMedia?->url(),
      'is_featured' => $this->is_featured,
      'is_active' => $this->is_active,
      'sort_order' => $this->sort_order,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
