<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsPage */
final class CmsPageResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'slug' => $this->slug,
      'status' => $this->status->value,
      'hero_title' => $this->hero_title,
      'hero_subtitle' => $this->hero_subtitle,
      'hero_media_id' => $this->heroMedia?->uuid ?? $this->hero_media_id,
      'hero_media_url' => $this->heroMedia ? $this->heroMedia->url() : null,
      'blocks' => $this->blocks,
      'published_at' => $this->published_at?->toIso8601String(),
      'scheduled_at' => $this->scheduled_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
