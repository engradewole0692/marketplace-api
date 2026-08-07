<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Counselling\Models\CounsellingCategory */
final class CounsellingCategoryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'description' => $this->description,
      'icon' => $this->icon,
      'sort_order' => (int) $this->sort_order,
      'is_visible' => (bool) $this->is_visible,
      'status' => $this->status,
      'seo_title' => $this->seo_title,
      'seo_description' => $this->seo_description,
      'services_count' => $this->when(isset($this->services_count), fn () => (int) $this->services_count),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
