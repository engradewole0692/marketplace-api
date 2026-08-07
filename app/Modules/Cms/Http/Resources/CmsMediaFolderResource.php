<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsMediaFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsMediaFolder */
final class CmsMediaFolderResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'parent_id' => $this->parent?->uuid,
      'sort_order' => $this->sort_order,
      'media_count' => $this->whenCounted('media'),
      'children' => CmsMediaFolderResource::collection($this->whenLoaded('children')),
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
