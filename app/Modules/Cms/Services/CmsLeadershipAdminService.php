<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CmsLeadershipAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsLeadershipProfile::query()->with(['country', 'ministry', 'photoMedia'])->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where('name', 'like', "%{$search}%");
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(array $data, User $actor): CmsLeadershipProfile
  {
    $data = $this->normalizeMediaIds($data);
    $profile = CmsLeadershipProfile::query()->create([
      ...$data,
      'slug' => $data['slug'] ?? Str::slug($data['name']),
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'leadership', $profile->id, $actor, null, ['name' => $profile->name]);
    $this->cacheManager->flushPublic();

    return $profile;
  }

  public function update(CmsLeadershipProfile $profile, array $data, User $actor): CmsLeadershipProfile
  {
    $data = $this->normalizeMediaIds($data);
    $old = $profile->only(['name', 'role', 'is_active', 'sort_order', 'photo_media_id']);
    $profile->fill([...$data, 'updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Updated, 'leadership', $profile->id, $actor, $old, $profile->only(['name', 'role', 'is_active', 'sort_order', 'photo_media_id']));
    $this->cacheManager->flushPublic();

    return $profile->fresh(['country', 'ministry', 'photoMedia']);
  }

  public function uploadPhoto(CmsLeadershipProfile $profile, UploadedFile $photo, User $actor): CmsLeadershipProfile
  {
    $path = $photo->store('cms/leadership', 'public');

    $media = CmsMedia::query()->create([
      'name' => pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME) ?: $profile->name,
      'file_name' => $photo->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $photo->getMimeType() ?? 'application/octet-stream',
      'size' => $photo->getSize() ?: 0,
      'alt_text' => $profile->name,
      'title' => $profile->name,
      'metadata' => ['entity' => 'leadership', 'profile_uuid' => $profile->uuid],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    return $this->update($profile, ['photo_media_id' => $media->id], $actor);
  }

  public function delete(CmsLeadershipProfile $profile, User $actor): void
  {
    $profile->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'leadership', $profile->id, $actor, ['name' => $profile->name], null);
    $this->cacheManager->flushPublic();
  }

  public function reorder(array $orderedIds, User $actor): void
  {
    foreach ($orderedIds as $index => $id) {
      CmsLeadershipProfile::query()->where('uuid', $id)->update([
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
    if (! array_key_exists('photo_media_id', $data)) {
      return $data;
    }

    $value = $data['photo_media_id'];
    if ($value === null || $value === '') {
      $data['photo_media_id'] = null;

      return $data;
    }

    if (! is_numeric($value)) {
      $data['photo_media_id'] = CmsMedia::query()->where('uuid', $value)->value('id');
    } else {
      $data['photo_media_id'] = (int) $value;
    }

    return $data;
  }
}
