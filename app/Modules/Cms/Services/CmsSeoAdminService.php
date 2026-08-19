<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class CmsSeoAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsSeo::query()->with('ogImage')->orderBy('path');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('path', 'like', "%{$search}%")
          ->orWhere('meta_title', 'like', "%{$search}%")
          ->orWhere('meta_description', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor): CmsSeo
  {
    $normalized = $this->normalize($data);

    $seo = CmsSeo::query()->create([
      ...$normalized,
      'entity_type' => $normalized['entity_type'] ?? 'path',
      'path' => $data['path'] ?? '/',
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'seo', $seo->id, $actor, null, ['path' => $seo->path]);
    $this->cacheManager->flushPageFromPath($seo->path);

    return $seo->load('ogImage');
  }

  public function update(CmsSeo $seo, array $data, User $actor): CmsSeo
  {
    $oldPath = $seo->path;
    $old = $seo->only(['path', 'meta_title', 'meta_description', 'canonical_url', 'no_index']);
    $seo->fill([...$this->normalize($data), 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'seo', $seo->id, $actor, $old, $seo->only(['path', 'meta_title', 'meta_description', 'canonical_url', 'no_index']));
    $this->cacheManager->flushPageFromPath($oldPath);
    if ($seo->path !== $oldPath) {
      $this->cacheManager->flushPageFromPath($seo->path);
    }

    return $seo->fresh('ogImage');
  }

  public function delete(CmsSeo $seo, User $actor): void
  {
    $path = $seo->path;
    $seo->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'seo', $seo->id, $actor, ['path' => $path], null);
    $this->cacheManager->flushPageFromPath($path);
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalize(array $data): array
  {
    $normalized = $data;

    if (! empty($data['og_image_id']) && ! is_numeric($data['og_image_id'])) {
      $normalized['og_image_id'] = CmsMedia::query()->where('uuid', $data['og_image_id'])->value('id');
    }

    if (! empty($data['path']) && ! str_starts_with((string) $data['path'], '/')) {
      $normalized['path'] = '/'.ltrim((string) $data['path'], '/');
    }

    if (! empty($data['meta_title']) && empty($data['slug_preview'])) {
      $normalized['meta_title'] = Str::limit((string) $data['meta_title'], 70, '');
    }

    return $normalized;
  }
}
