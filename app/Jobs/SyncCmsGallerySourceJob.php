<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Cms\Models\CmsGallerySource;
use App\Modules\Cms\Services\GoogleDriveGallerySyncService;

final class SyncCmsGallerySourceJob extends BaseJob
{
  public function __construct(
    public readonly int $gallerySourceId,
  ) {}

  public function handle(GoogleDriveGallerySyncService $sync): void
  {
    $source = CmsGallerySource::query()->find($this->gallerySourceId);
    if ($source === null || ! $source->is_enabled) {
      return;
    }

    $sync->sync($source);
  }
}
