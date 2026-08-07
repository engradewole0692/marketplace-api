<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Support\CmsCacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CmsCatalogAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsCacheManager $cacheManager,
  ) {}

  public function paginate(CatalogItemType $type, array $filters = []): LengthAwarePaginator
  {
    $query = CmsCatalogItem::query()
      ->where('type', $type)
      ->with('featuredMedia')
      ->orderByDesc('is_featured')
      ->orderBy('sort_order');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where('title', 'like', "%{$search}%");
    }

    if (! empty($filters['category'])) {
      $query->where('category', $filters['category']);
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function create(CatalogItemType $type, array $data, User $actor): CmsCatalogItem
  {
    $data = $this->normalizeMediaIds($data);
    $item = CmsCatalogItem::query()->create([
      ...$data,
      'type' => $type,
      'slug' => $data['slug'] ?? Str::slug($data['title']),
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'catalog_item', $item->id, $actor, null, ['type' => $type->value, 'slug' => $item->slug]);
    $this->cacheManager->flushCatalog($type->value);

    return $item;
  }

  public function update(CmsCatalogItem $item, array $data, User $actor): CmsCatalogItem
  {
    $data = $this->normalizeMediaIds($data);
    $old = $item->only(['title', 'slug', 'is_active', 'sort_order', 'featured_media_id']);
    $item->fill([...$data, 'updated_by' => $actor->id])->save();
    $this->auditService->record(
      CmsAuditEventType::Updated,
      'catalog_item',
      $item->id,
      $actor,
      $old,
      $item->only(['title', 'slug', 'is_active', 'sort_order', 'featured_media_id']),
    );
    $this->cacheManager->flushCatalog($item->type->value);

    return $item->fresh('featuredMedia');
  }

  public function uploadFeaturedMedia(CmsCatalogItem $item, UploadedFile $file, User $actor): CmsCatalogItem
  {
    $path = $file->store("cms/catalog/{$item->type->value}", 'public');

    $media = CmsMedia::query()->create([
      'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: $item->title,
      'file_name' => $file->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
      'size' => $file->getSize() ?: 0,
      'alt_text' => $item->title,
      'title' => $item->title,
      'metadata' => ['entity' => 'catalog_item', 'catalog_uuid' => $item->uuid, 'kind' => 'featured'],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    return $this->update($item, ['featured_media_id' => $media->id], $actor);
  }

  public function uploadResourceFile(CmsCatalogItem $item, UploadedFile $file, User $actor): CmsCatalogItem
  {
    abort_unless($item->type === CatalogItemType::Resource, 404);

    $path = $file->store('cms/catalog/resources/files', 'public');

    $media = CmsMedia::query()->create([
      'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: $item->title,
      'file_name' => $file->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
      'size' => $file->getSize() ?: 0,
      'alt_text' => $item->title,
      'title' => $item->title,
      'metadata' => ['entity' => 'catalog_item', 'catalog_uuid' => $item->uuid, 'kind' => 'resource_file'],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $metadata = $item->metadata ?? [];
    $metadata['file_url'] = $media->url();
    $metadata['download_url'] = $media->url();
    $metadata['file_size'] = $this->humanFileSize($media->size);
    $metadata['file_size_bytes'] = $media->size;
    $metadata['file_media_id'] = $media->id;

    return $this->update($item, ['metadata' => $metadata], $actor);
  }

  /**
   * @param list<string> $ids
   */
  public function reorder(CatalogItemType $type, array $ids, User $actor): void
  {
    foreach ($ids as $index => $uuid) {
      CmsCatalogItem::query()
        ->where('type', $type)
        ->where('uuid', $uuid)
        ->update(['sort_order' => $index + 1, 'updated_by' => $actor->id]);
    }

    $this->auditService->record(CmsAuditEventType::Updated, 'catalog_item', 0, $actor, null, [
      'type' => $type->value,
      'ids' => $ids,
    ]);
    $this->cacheManager->flushCatalog($type->value);
  }

  public function delete(CmsCatalogItem $item, User $actor): void
  {
    $type = $item->type->value;
    $item->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'catalog_item', $item->id, $actor, ['slug' => $item->slug], null);
    $this->cacheManager->flushCatalog($type);
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalizeMediaIds(array $data): array
  {
    if (! array_key_exists('featured_media_id', $data)) {
      return $data;
    }

    $value = $data['featured_media_id'];
    if ($value === null || $value === '') {
      $data['featured_media_id'] = null;

      return $data;
    }

    if (! is_numeric($value)) {
      $data['featured_media_id'] = CmsMedia::query()->where('uuid', $value)->value('id');
    } else {
      $data['featured_media_id'] = (int) $value;
    }

    return $data;
  }

  private function humanFileSize(int $bytes): string
  {
    if ($bytes >= 1_073_741_824) {
      return round($bytes / 1_073_741_824, 1).' GB';
    }

    if ($bytes >= 1_048_576) {
      return round($bytes / 1_048_576, 1).' MB';
    }

    if ($bytes >= 1024) {
      return round($bytes / 1024, 1).' KB';
    }

    return $bytes.' B';
  }
}
