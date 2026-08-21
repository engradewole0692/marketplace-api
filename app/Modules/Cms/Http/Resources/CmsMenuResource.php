<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsMenu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsMenu */
final class CmsMenuResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'location' => $this->location,
      'is_active' => $this->is_active,
      'items' => $this->relationLoaded('items')
        ? CmsMenuItemResource::collection($this->items)->resolve()
        : [],
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
