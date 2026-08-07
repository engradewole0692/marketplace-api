<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsSeo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsSeo */
final class CmsSeoResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'entity_type' => $this->entity_type,
      'entity_id' => $this->entity_id,
      'path' => $this->path,
      'meta_title' => $this->meta_title,
      'meta_description' => $this->meta_description,
      'meta_keywords' => $this->meta_keywords,
      'canonical_url' => $this->canonical_url,
      'og_title' => $this->og_title,
      'og_description' => $this->og_description,
      'og_image_id' => $this->ogImage?->uuid,
      'og_image_url' => $this->ogImage?->url(),
      'twitter_card' => $this->twitter_card,
      'json_ld' => $this->json_ld,
      'no_index' => $this->no_index,
      'robots' => $this->robots,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
