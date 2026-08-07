<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsCatalogItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsCatalogItem */
final class CmsCatalogItemResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'type' => $this->type->value,
      'title' => $this->title,
      'slug' => $this->slug,
      'summary' => $this->summary,
      'body' => $this->body,
      'metadata' => $this->metadata,
      'category' => $this->category,
      'tags' => $this->tags,
      'featured_media_id' => $this->featuredMedia?->uuid,
      'featured_image_url' => $this->featuredMedia?->url(),
      'is_active' => $this->is_active,
      'is_featured' => $this->is_featured,
      'sort_order' => $this->sort_order,
      'published_at' => $this->published_at?->toIso8601String(),
    ];
  }
}
