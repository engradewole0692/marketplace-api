<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Cms\Http\Resources\CmsMinistryResource;
use App\Modules\Events\Models\EventCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventCategory */
final class EventCategoryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'ministry' => $this->whenLoaded('ministry', fn () => new CmsMinistryResource($this->ministry)),
      'name' => $this->name,
      'slug' => $this->slug,
      'description' => $this->description,
      'status' => $this->status,
      'sort_order' => $this->sort_order,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
