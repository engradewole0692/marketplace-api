<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsGallerySource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsGallerySource */
final class CmsGallerySourceResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'provider' => $this->provider,
      'source_url' => $this->source_url,
      'remote_id' => $this->remote_id,
      'access_status' => $this->access_status,
      'is_enabled' => $this->is_enabled,
      'last_synced_at' => $this->last_synced_at?->toIso8601String(),
      'imported_count' => $this->imported_count,
      'skipped_count' => $this->skipped_count,
      'duplicate_count' => $this->duplicate_count,
      'failed_count' => $this->failed_count,
      'last_error' => $this->last_error,
      'metadata' => $this->metadata,
    ];
  }
}
