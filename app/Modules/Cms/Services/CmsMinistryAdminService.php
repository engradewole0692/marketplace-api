<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CmsMinistryAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsMinistry::query()
      ->with(['heroMedia', 'logoMedia', 'bannerMedia', 'coverMedia', 'leaderMember', 'assistantLeaderMember'])
      ->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where('name', 'like', "%{$search}%");
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor): CmsMinistry
  {
    $data = $this->normalizeMediaIds($data);
    $ministry = CmsMinistry::query()->create([
      ...$data,
      'slug' => $data['slug'] ?? Str::slug($data['name']),
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'ministry', $ministry->id, $actor, null, ['name' => $ministry->name]);
    $this->cacheManager->flushPublic();

    return $ministry;
  }

  public function update(CmsMinistry $ministry, array $data, User $actor): CmsMinistry
  {
    $data = $this->normalizeMediaIds($data);
    $old = $ministry->only(['name', 'slug', 'is_active', 'sort_order', 'hero_media_id']);
    $ministry->fill([...$data, 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'ministry', $ministry->id, $actor, $old, $ministry->only(['name', 'slug', 'is_active', 'sort_order', 'hero_media_id']));
    $this->cacheManager->flushPublic();

    return $ministry->fresh(['heroMedia', 'logoMedia', 'bannerMedia', 'coverMedia', 'leaderMember', 'assistantLeaderMember']);
  }

  public function uploadImage(CmsMinistry $ministry, UploadedFile $image, User $actor): CmsMinistry
  {
    $path = $image->store('cms/ministries', 'public');

    $media = CmsMedia::query()->create([
      'name' => pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME) ?: $ministry->name,
      'file_name' => $image->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $image->getMimeType() ?? 'application/octet-stream',
      'size' => $image->getSize() ?: 0,
      'alt_text' => $ministry->name,
      'title' => $ministry->name,
      'metadata' => ['entity' => 'ministry', 'ministry_uuid' => $ministry->uuid],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    return $this->update($ministry, ['hero_media_id' => $media->id], $actor);
  }

  public function delete(CmsMinistry $ministry, User $actor): void
  {
    $ministry->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'ministry', $ministry->id, $actor, ['name' => $ministry->name], null);
    $this->cacheManager->flushPublic();
  }

  public function reorder(array $orderedIds, User $actor): void
  {
    foreach ($orderedIds as $index => $id) {
      CmsMinistry::query()->where('uuid', $id)->update([
        'sort_order' => $index + 1,
        'updated_by' => $actor->id,
      ]);
    }

    $this->cacheManager->flushPublic();
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalizeMediaIds(array $data): array
  {
    foreach (['hero_media_id', 'logo_media_id', 'banner_media_id', 'cover_media_id'] as $key) {
      if (! array_key_exists($key, $data)) {
        continue;
      }

      $value = $data[$key];
      if ($value === null || $value === '') {
        $data[$key] = null;
        continue;
      }

      if (! is_numeric($value)) {
        $data[$key] = CmsMedia::query()->where('uuid', $value)->value('id');
      } else {
        $data[$key] = (int) $value;
      }
    }

    return $data;
  }
}
