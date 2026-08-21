<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsMenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsMenuItem */
final class CmsMenuItemResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'label' => $this->label,
      'url' => $this->url,
      'route_name' => $this->route_name,
      'icon' => $this->icon,
      'open_in_new_tab' => $this->open_in_new_tab,
      'is_active' => $this->is_active,
      'sort_order' => $this->sort_order,
      'children' => $this->relationLoaded('children')
        ? self::collection($this->children)->resolve()
        : [],
    ];
  }
}
