<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventGalleryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventGalleryItem */
final class EventGalleryItemResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'caption' => $this->caption,
      'media_type' => $this->media_type,
      'media_url' => $this->media_url,
      'alt_text' => $this->alt_text,
      'sort_order' => $this->sort_order,
      'is_featured' => $this->is_featured,
    ];
  }
}
