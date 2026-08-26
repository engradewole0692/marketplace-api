<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Jobs\SyncCmsGallerySourceJob;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsGallerySource;
use Illuminate\Support\Collection;

final class CmsGallerySourceAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly GoogleDriveGallerySyncService $syncService,
  ) {}

  /**
   * @return Collection<int, CmsGallerySource>
   */
  public function all(): Collection
  {
    return CmsGallerySource::query()->orderBy('id')->get();
  }

  /**
   * @param  array{is_enabled?: bool, name?: string}  $data
   */
  public function update(CmsGallerySource $source, array $data, User $actor): CmsGallerySource
  {
    $old = $source->only(['is_enabled', 'name']);
    $source->fill([...$data, 'updated_by' => $actor->id])->save();
    $this->auditService->record(
      CmsAuditEventType::Updated,
      'gallery_source',
      $source->id,
      $actor,
      $old,
      $source->only(['is_enabled', 'name']),
    );

    return $source->fresh() ?? $source;
  }

  /**
   * @return array{imported: int, skipped: int, duplicates: int, failed: int, status: string, error: ?string}
   */
  public function syncNow(CmsGallerySource $source, ?User $actor = null): array
  {
    $result = $this->syncService->sync($source);
    if ($actor !== null) {
      $this->auditService->record(
        CmsAuditEventType::Updated,
        'gallery_source',
        $source->id,
        $actor,
        null,
        ['sync' => $result],
      );
    }

    return $result;
  }

  public function dispatchEnabled(): int
  {
    $count = 0;
    CmsGallerySource::query()->where('is_enabled', true)->each(function (CmsGallerySource $source) use (&$count): void {
      SyncCmsGallerySourceJob::dispatch($source->id);
      $count++;
    });

    return $count;
  }
}
