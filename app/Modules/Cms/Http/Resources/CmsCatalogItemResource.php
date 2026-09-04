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
      'title' => is_string($this->title) ? $this->title : '',
      'slug' => is_string($this->slug) ? $this->slug : '',
      'summary' => is_string($this->summary) ? $this->summary : '',
      'body' => is_string($this->body) ? $this->body : null,
      'metadata' => is_array($this->metadata) ? $this->metadata : (object) [],
      'category' => is_string($this->category) ? $this->category : null,
      'tags' => $this->scalarTags(),
      'featured_media_id' => $this->featuredMedia?->uuid,
      'featured_image_url' => $this->featuredMedia?->url(),
      'is_active' => $this->is_active,
      'is_featured' => $this->is_featured,
      'sort_order' => $this->sort_order,
      'published_at' => $this->published_at?->toIso8601String(),
    ];
  }

  /**
   * @return list<string>
   */
  private function scalarTags(): array
  {
    $tags = $this->tags;
    if (! is_array($tags)) {
      return [];
    }

    $out = [];
    foreach ($tags as $tag) {
      if (is_string($tag) && $tag !== '') {
        $out[] = $tag;

        continue;
      }
      if (is_array($tag) && isset($tag['name']) && is_string($tag['name']) && $tag['name'] !== '') {
        $out[] = $tag['name'];
      }
    }

    return array_values(array_unique($out));
  }
}
