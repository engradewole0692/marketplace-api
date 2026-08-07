<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CmsCountryAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = CmsCountry::query()->with('heroMedia')->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('code', 'like', "%{$search}%");
      });
    }

    if (isset($filters['is_active'])) {
      $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }

  public function create(array $data, User $actor): CmsCountry
  {
    $data = $this->normalizeMediaIds($data);
    $country = CmsCountry::query()->create([
      ...$data,
      'slug' => $data['slug'] ?? Str::slug($data['name']),
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(
      CmsAuditEventType::Created,
      'country',
      $country->id,
      $actor,
      null,
      $country->only(['name', 'slug', 'code', 'is_active']),
    );

    $this->flushPublicCache();

    return $country->fresh('heroMedia');
  }

  public function update(CmsCountry $country, array $data, User $actor): CmsCountry
  {
    $data = $this->normalizeMediaIds($data);
    $old = $country->only(['name', 'slug', 'code', 'is_active', 'latitude', 'longitude', 'hero_media_id']);
    $country->fill([...$data, 'updated_by' => $actor->id]);
    $country->save();

    $this->auditService->record(
      CmsAuditEventType::Updated,
      'country',
      $country->id,
      $actor,
      $old,
      $country->only(['name', 'slug', 'code', 'is_active', 'latitude', 'longitude', 'hero_media_id']),
    );

    $this->flushPublicCache();

    return $country->fresh('heroMedia');
  }

  public function uploadImage(CmsCountry $country, UploadedFile $image, User $actor): CmsCountry
  {
    $path = $image->store('cms/countries', 'public');

    $media = CmsMedia::query()->create([
      'name' => pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME) ?: $country->name,
      'file_name' => $image->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $image->getMimeType() ?? 'application/octet-stream',
      'size' => $image->getSize() ?: 0,
      'alt_text' => $country->name,
      'title' => $country->name,
      'metadata' => ['entity' => 'country', 'country_uuid' => $country->uuid],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    return $this->update($country, ['hero_media_id' => $media->id], $actor);
  }

  public function delete(CmsCountry $country, User $actor): void
  {
    $old = $country->only(['name', 'slug']);
    $country->delete();

    $this->auditService->record(
      CmsAuditEventType::Deleted,
      'country',
      $country->id,
      $actor,
      $old,
      null,
    );

    $this->flushPublicCache();
  }

  public function reorder(array $orderedIds, User $actor): void
  {
    foreach ($orderedIds as $index => $id) {
      CmsCountry::query()->where('uuid', $id)->update([
        'sort_order' => $index + 1,
        'updated_by' => $actor->id,
      ]);
    }

    $this->flushPublicCache();
  }

  private function flushPublicCache(): void
  {
    $this->cacheManager->flushPublic();
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalizeMediaIds(array $data): array
  {
    if (! array_key_exists('hero_media_id', $data)) {
      return $data;
    }

    $value = $data['hero_media_id'];
    if ($value === null || $value === '') {
      $data['hero_media_id'] = null;

      return $data;
    }

    if (! is_numeric($value)) {
      $data['hero_media_id'] = CmsMedia::query()->where('uuid', $value)->value('id');
    } else {
      $data['hero_media_id'] = (int) $value;
    }

    return $data;
  }
}
