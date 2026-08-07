<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Models\CmsMedia;

final class MediaAttachmentService implements ServiceContract
{
  /**
   * Resolves a CMS media UUID (as submitted by admin UI file pickers) to its
   * internal auto-increment id for storage on *_media_id foreign keys.
   */
  public function resolveUuidToId(?string $uuid): ?int
  {
    if ($uuid === null || $uuid === '') {
      return null;
    }

    return CmsMedia::query()->where('uuid', $uuid)->value('id');
  }
}
