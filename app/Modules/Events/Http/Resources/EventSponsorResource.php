<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventSponsor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventSponsor */
final class EventSponsorResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'logo_url' => $this->logo_url,
      'website_url' => $this->website_url,
      'description' => $this->description,
      'sort_order' => $this->sort_order,
    ];
  }
}
