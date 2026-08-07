<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Services\CmsMediaUsageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsMedia */
final class CmsMediaResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $metadata = $this->metadata ?? [];

    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'file_name' => $this->file_name,
      'mime_type' => $this->mime_type,
      'size' => $this->size,
      'width' => $this->width,
      'height' => $this->height,
      'alt_text' => $this->alt_text,
      'title' => $this->title,
      'caption' => $metadata['caption'] ?? null,
      'description' => $metadata['description'] ?? null,
      'credits' => $this->credits,
      'copyright' => $this->copyright,
      'tags' => $this->tags ?? [],
      'focal_x' => $this->focal_x,
      'focal_y' => $this->focal_y,
      'is_optimized' => (bool) $this->is_optimized,
      'url' => $this->url(),
      'thumbnail_url' => $this->thumbnailUrl(),
      'responsive' => $this->responsiveUrls(),
      'webp' => $this->webpUrls(),
      'variants' => $this->variants,
      'folder_id' => $this->folder?->uuid,
      'metadata' => $this->metadata,
      'deleted_at' => $this->deleted_at?->toIso8601String(),
      'usages' => $this->when(
        str_ends_with((string) $request->route()?->getName(), 'cms.media.show') || $request->boolean('include_usage'),
        fn () => app(CmsMediaUsageService::class)->references($this->resource),
      ),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
