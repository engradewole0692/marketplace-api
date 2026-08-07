<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Exceptions\CmsMediaInUseException;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMediaFolder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CmsMediaAdminService implements ServiceContract
{
  public function __construct(
    private readonly CmsAuditService $auditService,
    private readonly CmsMediaUsageService $usageService,
    private readonly CmsMediaImagePipeline $imagePipeline,
  ) {}

  public function paginateMedia(array $filters = []): LengthAwarePaginator
  {
    $trashed = ($filters['trashed'] ?? null) === 'only' || ($filters['trashed'] ?? false) === true;

    $query = $trashed
      ? CmsMedia::onlyTrashed()->with('folder')
      : CmsMedia::query()->with('folder');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('name', 'like', "%{$search}%")
          ->orWhere('file_name', 'like', "%{$search}%")
          ->orWhere('title', 'like', "%{$search}%")
          ->orWhere('alt_text', 'like', "%{$search}%")
          ->orWhere('credits', 'like', "%{$search}%")
          ->orWhere('copyright', 'like', "%{$search}%");
      });
    }

    if (array_key_exists('folder_id', $filters) && ! $trashed) {
      if ($filters['folder_id'] === null || $filters['folder_id'] === '' || $filters['folder_id'] === 'root') {
        $query->whereNull('folder_id');
      } elseif ($filters['folder_id'] !== 'all') {
        $folder = CmsMediaFolder::query()->where('uuid', $filters['folder_id'])->first();
        $query->where('folder_id', $folder?->id);
      }
    }

    if (! empty($filters['mime_type'])) {
      $query->where('mime_type', 'like', $filters['mime_type'].'%');
    }

    if (! empty($filters['tag'])) {
      $tag = (string) $filters['tag'];
      $query->whereJsonContains('tags', $tag);
    }

    $sort = (string) ($filters['sort'] ?? 'created_at');
    $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $allowedSorts = ['created_at', 'name', 'file_name', 'size', 'mime_type', 'deleted_at'];
    if (! in_array($sort, $allowedSorts, true)) {
      $sort = 'created_at';
    }

    $query->orderBy($sort, $direction);

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function show(CmsMedia $media): CmsMedia
  {
    return $media->load('folder');
  }

  /**
   * @return Collection<int, CmsMediaFolder>
   */
  public function folderTree(): Collection
  {
    $folders = CmsMediaFolder::query()
      ->withCount('media')
      ->orderBy('sort_order')
      ->orderBy('name')
      ->get();

    return $this->buildFolderTree($folders);
  }

  public function createFolder(array $data, User $actor): CmsMediaFolder
  {
    $parentId = null;
    if (! empty($data['parent_id'])) {
      $parent = CmsMediaFolder::query()->where('uuid', $data['parent_id'])->firstOrFail();
      $parentId = $parent->id;
    }

    $folder = CmsMediaFolder::query()->create([
      'name' => $data['name'],
      'slug' => $data['slug'] ?? Str::slug($data['name']),
      'parent_id' => $parentId,
      'sort_order' => $data['sort_order'] ?? 0,
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'media_folder', $folder->id, $actor, null, ['name' => $folder->name]);

    return $folder;
  }

  public function updateFolder(CmsMediaFolder $folder, array $data, User $actor): CmsMediaFolder
  {
    $old = $folder->only(['name', 'slug', 'parent_id', 'sort_order']);

    if (array_key_exists('parent_id', $data)) {
      $folder->parent_id = $data['parent_id']
        ? CmsMediaFolder::query()->where('uuid', $data['parent_id'])->value('id')
        : null;
    }

    $folder->fill([
      'name' => $data['name'] ?? $folder->name,
      'slug' => $data['slug'] ?? $folder->slug,
      'sort_order' => $data['sort_order'] ?? $folder->sort_order,
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(CmsAuditEventType::Updated, 'media_folder', $folder->id, $actor, $old, $folder->only(['name', 'slug', 'parent_id', 'sort_order']));

    return $folder->fresh('parent');
  }

  public function deleteFolder(CmsMediaFolder $folder, User $actor): void
  {
    CmsMedia::query()->where('folder_id', $folder->id)->update(['folder_id' => null]);
    $folder->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'media_folder', $folder->id, $actor, ['name' => $folder->name], null);
  }

  /**
   * @return array{media: CmsMedia, deduplicated: bool}
   */
  public function upload(UploadedFile $file, User $actor, ?string $folderUuid = null, ?string $name = null): array
  {
    $hash = hash_file('sha256', $file->getRealPath() ?: $file->getPathname());
    $existing = CmsMedia::query()->where('content_hash', $hash)->first();
    if ($existing !== null) {
      return ['media' => $existing->load('folder'), 'deduplicated' => true];
    }

    $folderId = null;
    if ($folderUuid) {
      $folderId = CmsMediaFolder::query()->where('uuid', $folderUuid)->value('id');
    }

    $path = $file->store('cms/media', 'public');
    $mimeType = $file->getMimeType() ?? 'application/octet-stream';
    $displayName = $name ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'upload';
    $processed = $this->imagePipeline->process('public', $path, $mimeType);

    $media = CmsMedia::query()->create([
      'folder_id' => $folderId,
      'name' => $displayName,
      'file_name' => $file->getClientOriginalName(),
      'disk' => 'public',
      'path' => $path,
      'content_hash' => $hash,
      'mime_type' => $mimeType,
      'size' => $file->getSize() ?: 0,
      'width' => $processed['width'],
      'height' => $processed['height'],
      'title' => $displayName,
      'thumbnail_path' => $processed['thumbnail_path'],
      'variants' => $processed['variants'],
      'is_optimized' => $processed['is_optimized'],
      'created_by' => $actor->id,
      'updated_by' => $actor->id,
    ]);

    $this->auditService->record(CmsAuditEventType::Created, 'media', $media->id, $actor, null, ['name' => $media->name]);

    return ['media' => $media->load('folder'), 'deduplicated' => false];
  }

  /**
   * @param  list<UploadedFile>  $files
   * @return list<array{media: CmsMedia, deduplicated: bool}>
   */
  public function bulkUpload(array $files, User $actor, ?string $folderUuid = null): array
  {
    $results = [];
    foreach ($files as $file) {
      $results[] = $this->upload($file, $actor, $folderUuid);
    }

    return $results;
  }

  public function replaceFile(CmsMedia $media, UploadedFile $file, User $actor): CmsMedia
  {
    $this->deleteVariantFiles($media);

    if (Storage::disk($media->disk)->exists($media->path)) {
      Storage::disk($media->disk)->delete($media->path);
    }

    $path = $file->store('cms/media', 'public');
    $mimeType = $file->getMimeType() ?? 'application/octet-stream';
    $hash = hash_file('sha256', $file->getRealPath() ?: $file->getPathname());
    $processed = $this->imagePipeline->process($media->disk, $path, $mimeType, $media->focal_x, $media->focal_y);
    $old = $media->only(['path', 'file_name', 'mime_type', 'size', 'thumbnail_path', 'variants']);

    $media->fill([
      'path' => $path,
      'content_hash' => $hash,
      'file_name' => $file->getClientOriginalName(),
      'mime_type' => $mimeType,
      'size' => $file->getSize() ?: 0,
      'width' => $processed['width'],
      'height' => $processed['height'],
      'thumbnail_path' => $processed['thumbnail_path'],
      'variants' => $processed['variants'],
      'is_optimized' => $processed['is_optimized'],
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(CmsAuditEventType::Updated, 'media', $media->id, $actor, $old, $media->only(['path', 'file_name', 'mime_type', 'size', 'thumbnail_path', 'variants']));

    return $media->fresh('folder');
  }

  public function updateMedia(CmsMedia $media, array $data, User $actor): CmsMedia
  {
    $old = $media->only(['name', 'title', 'alt_text', 'folder_id', 'metadata', 'tags', 'credits', 'copyright', 'focal_x', 'focal_y']);
    $shouldReprocess = false;

    if (array_key_exists('folder_id', $data)) {
      $media->folder_id = $data['folder_id']
        ? CmsMediaFolder::query()->where('uuid', $data['folder_id'])->value('id')
        : null;
    }

    $metadata = $media->metadata ?? [];
    if (array_key_exists('caption', $data)) {
      $metadata['caption'] = $data['caption'];
    }
    if (array_key_exists('description', $data)) {
      $metadata['description'] = $data['description'];
    }

    if (array_key_exists('focal_x', $data) || array_key_exists('focal_y', $data)) {
      $shouldReprocess = true;
    }

    $media->fill([
      'name' => $data['name'] ?? $media->name,
      'title' => $data['title'] ?? $media->title,
      'alt_text' => $data['alt_text'] ?? $media->alt_text,
      'credits' => array_key_exists('credits', $data) ? $data['credits'] : $media->credits,
      'copyright' => array_key_exists('copyright', $data) ? $data['copyright'] : $media->copyright,
      'focal_x' => array_key_exists('focal_x', $data) ? $data['focal_x'] : $media->focal_x,
      'focal_y' => array_key_exists('focal_y', $data) ? $data['focal_y'] : $media->focal_y,
      'tags' => array_key_exists('tags', $data) ? array_values($data['tags'] ?? []) : $media->tags,
      'metadata' => $metadata,
      'updated_by' => $actor->id,
    ])->save();

    if ($shouldReprocess && str_starts_with((string) $media->mime_type, 'image/')) {
      $this->deleteVariantFiles($media);
      $processed = $this->imagePipeline->process($media->disk, $media->path, $media->mime_type, $media->focal_x, $media->focal_y);
      $media->fill([
        'width' => $processed['width'],
        'height' => $processed['height'],
        'thumbnail_path' => $processed['thumbnail_path'],
        'variants' => $processed['variants'],
        'is_optimized' => $processed['is_optimized'],
      ])->save();
    }

    $this->auditService->record(CmsAuditEventType::Updated, 'media', $media->id, $actor, $old, $media->only(['name', 'title', 'alt_text', 'folder_id', 'metadata', 'tags', 'credits', 'copyright', 'focal_x', 'focal_y']));

    return $media->fresh('folder');
  }

  public function deleteMedia(CmsMedia $media, User $actor): void
  {
    if ($this->usageService->isInUse($media)) {
      throw new CmsMediaInUseException($this->usageService->references($media));
    }

    // Soft-delete only — files remain until force delete (recycle bin).
    $media->delete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'media', $media->id, $actor, ['name' => $media->name], null);
  }

  public function restoreMedia(CmsMedia $media, User $actor): CmsMedia
  {
    $media->restore();
    $media->fill(['updated_by' => $actor->id])->save();
    $this->auditService->record(CmsAuditEventType::Restored, 'media', $media->id, $actor, null, ['name' => $media->name]);

    return $media->fresh('folder');
  }

  public function forceDeleteMedia(CmsMedia $media, User $actor): void
  {
    if (! $media->trashed() && $this->usageService->isInUse($media)) {
      throw new CmsMediaInUseException($this->usageService->references($media));
    }

    $this->deleteAllFiles($media);
    $id = $media->id;
    $name = $media->name;
    $media->forceDelete();
    $this->auditService->record(CmsAuditEventType::Deleted, 'media', $id, $actor, ['name' => $name, 'force' => true], null);
  }

  /**
   * @param  list<string>  $mediaUuids
   */
  public function bulkDeleteMedia(array $mediaUuids, User $actor): int
  {
    $count = 0;

    foreach (CmsMedia::query()->whereIn('uuid', $mediaUuids)->get() as $media) {
      $this->deleteMedia($media, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  list<string>  $mediaUuids
   */
  public function bulkRestoreMedia(array $mediaUuids, User $actor): int
  {
    $count = 0;
    foreach (CmsMedia::onlyTrashed()->whereIn('uuid', $mediaUuids)->get() as $media) {
      $this->restoreMedia($media, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  list<string>  $mediaUuids
   */
  public function bulkForceDeleteMedia(array $mediaUuids, User $actor): int
  {
    $count = 0;
    foreach (CmsMedia::withTrashed()->whereIn('uuid', $mediaUuids)->get() as $media) {
      $this->forceDeleteMedia($media, $actor);
      $count++;
    }

    return $count;
  }

  /**
   * @param  list<string>  $mediaUuids
   */
  public function bulkMoveMedia(array $mediaUuids, ?string $folderUuid, User $actor): int
  {
    $count = 0;
    foreach (CmsMedia::query()->whereIn('uuid', $mediaUuids)->get() as $media) {
      $this->updateMedia($media, ['folder_id' => $folderUuid], $actor);
      $count++;
    }

    return $count;
  }

  public function duplicateMedia(CmsMedia $media, User $actor, ?string $folderUuid = null): CmsMedia
  {
    $disk = Storage::disk($media->disk);
    $extension = pathinfo($media->path, PATHINFO_EXTENSION);
    $newPath = 'cms/media/'.uniqid('copy_', true).($extension ? '.'.$extension : '');
    if ($disk->exists($media->path)) {
      $disk->copy($media->path, $newPath);
    }

    $folderId = $media->folder_id;
    if ($folderUuid !== null) {
      $folderId = $folderUuid === ''
        ? null
        : CmsMediaFolder::query()->where('uuid', $folderUuid)->value('id');
    }

    $copy = $media->replicate([
      'uuid',
      'content_hash',
      'thumbnail_path',
      'variants',
    ]);
    $copy->uuid = (string) Str::uuid();
    $copy->path = $newPath;
    $copy->name = $media->name.' (copy)';
    $copy->folder_id = $folderId;
    $copy->content_hash = $disk->exists($newPath) ? hash_file('sha256', $disk->path($newPath)) : null;
    $copy->created_by = $actor->id;
    $copy->updated_by = $actor->id;
    $copy->save();

    if (str_starts_with((string) $copy->mime_type, 'image/')) {
      $processed = $this->imagePipeline->process($copy->disk, $copy->path, $copy->mime_type, $copy->focal_x, $copy->focal_y);
      $copy->fill([
        'width' => $processed['width'],
        'height' => $processed['height'],
        'thumbnail_path' => $processed['thumbnail_path'],
        'variants' => $processed['variants'],
        'is_optimized' => $processed['is_optimized'],
      ])->save();
    }

    $this->auditService->record(CmsAuditEventType::Created, 'media', $copy->id, $actor, null, [
      'name' => $copy->name,
      'duplicated_from' => $media->uuid,
    ]);

    return $copy->fresh('folder');
  }

  public function cropMedia(CmsMedia $media, array $crop, User $actor, bool $replace = false, int $outputWidth = 1280): CmsMedia
  {
    $result = $this->imagePipeline->cropToNewFile($media->disk, $media->path, $media->mime_type, $crop, $outputWidth);
    if ($result === null) {
      abort(422, 'Unable to crop media file.');
    }

    if ($replace) {
      $this->deleteVariantFiles($media);
      if (Storage::disk($media->disk)->exists($media->path)) {
        Storage::disk($media->disk)->delete($media->path);
      }

      $processed = $this->imagePipeline->process($media->disk, $result['path'], $result['mime_type'], $media->focal_x, $media->focal_y);
      $media->fill([
        'path' => $result['path'],
        'mime_type' => $result['mime_type'],
        'size' => $result['size'],
        'content_hash' => hash_file('sha256', Storage::disk($media->disk)->path($result['path'])),
        'width' => $processed['width'],
        'height' => $processed['height'],
        'thumbnail_path' => $processed['thumbnail_path'],
        'variants' => $processed['variants'],
        'is_optimized' => $processed['is_optimized'],
        'updated_by' => $actor->id,
      ])->save();

      $this->auditService->record(CmsAuditEventType::Updated, 'media', $media->id, $actor, null, ['crop' => true]);

      return $media->fresh('folder');
    }

    $upload = new UploadedFile(
      Storage::disk($media->disk)->path($result['path']),
      pathinfo($result['path'], PATHINFO_BASENAME),
      $result['mime_type'],
      null,
      true,
    );

    return $this->upload($upload, $actor, $media->folder?->uuid, $media->name.' (cropped)')['media'];
  }

  public function resizeMedia(CmsMedia $media, int $maxWidth, int $maxHeight, User $actor, bool $replace = false): CmsMedia
  {
    $result = $this->imagePipeline->resizeToNewFile($media->disk, $media->path, $media->mime_type, $maxWidth, $maxHeight);
    if ($result === null) {
      abort(422, 'Unable to resize media file.');
    }

    if ($replace) {
      $this->deleteVariantFiles($media);
      if (Storage::disk($media->disk)->exists($media->path)) {
        Storage::disk($media->disk)->delete($media->path);
      }

      $processed = $this->imagePipeline->process($media->disk, $result['path'], $result['mime_type'], $media->focal_x, $media->focal_y);
      $media->fill([
        'path' => $result['path'],
        'mime_type' => $result['mime_type'],
        'size' => $result['size'],
        'content_hash' => hash_file('sha256', Storage::disk($media->disk)->path($result['path'])),
        'width' => $processed['width'],
        'height' => $processed['height'],
        'thumbnail_path' => $processed['thumbnail_path'],
        'variants' => $processed['variants'],
        'is_optimized' => $processed['is_optimized'],
        'updated_by' => $actor->id,
      ])->save();

      return $media->fresh('folder');
    }

    $upload = new UploadedFile(
      Storage::disk($media->disk)->path($result['path']),
      pathinfo($result['path'], PATHINFO_BASENAME),
      $result['mime_type'],
      null,
      true,
    );

    return $this->upload($upload, $actor, $media->folder?->uuid, $media->name.' (resized)')['media'];
  }

  public function optimizeMedia(CmsMedia $media, User $actor): CmsMedia
  {
    if (! str_starts_with((string) $media->mime_type, 'image/')) {
      return $media;
    }

    $this->deleteVariantFiles($media);
    $processed = $this->imagePipeline->process($media->disk, $media->path, $media->mime_type, $media->focal_x, $media->focal_y);
    $media->fill([
      'width' => $processed['width'],
      'height' => $processed['height'],
      'thumbnail_path' => $processed['thumbnail_path'],
      'variants' => $processed['variants'],
      'is_optimized' => $processed['is_optimized'],
      'updated_by' => $actor->id,
    ])->save();

    $this->auditService->record(CmsAuditEventType::Updated, 'media', $media->id, $actor, null, ['optimize' => true]);

    return $media->fresh('folder');
  }

  /**
   * @return array<string, mixed>
   */
  public function storageStatistics(): array
  {
    $active = CmsMedia::query();
    $trashed = CmsMedia::onlyTrashed();

    $byMime = CmsMedia::query()
      ->select('mime_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(size) as bytes'))
      ->groupBy('mime_type')
      ->get()
      ->map(fn ($row) => [
        'mime_type' => $row->mime_type,
        'count' => (int) $row->count,
        'bytes' => (int) $row->bytes,
      ])
      ->values()
      ->all();

    return [
      'total' => (clone $active)->count(),
      'trashed' => (clone $trashed)->count(),
      'storage_bytes' => (int) (clone $active)->sum('size'),
      'storage_mb' => round(((int) (clone $active)->sum('size')) / 1024 / 1024, 2),
      'optimized' => (clone $active)->where('is_optimized', true)->count(),
      'images' => (clone $active)->where('mime_type', 'like', 'image/%')->count(),
      'by_mime' => $byMime,
      'folders' => CmsMediaFolder::query()->count(),
    ];
  }

  /**
   * @return list<array{id: string, name: string, path: string, reason: string}>
   */
  public function detectBrokenMedia(): array
  {
    $broken = [];

    foreach (CmsMedia::query()->get(['uuid', 'name', 'disk', 'path', 'thumbnail_path']) as $media) {
      $disk = Storage::disk($media->disk);
      if (! $disk->exists($media->path)) {
        $broken[] = [
          'id' => $media->uuid,
          'name' => $media->name,
          'path' => $media->path,
          'reason' => 'missing_original',
        ];
      }
    }

    return $broken;
  }

  /**
   * @return list<array{id: string, name: string, url: string, size: int}>
   */
  public function detectUnusedMedia(): array
  {
    $unused = [];

    foreach (CmsMedia::query()->orderByDesc('created_at')->limit(500)->get() as $media) {
      if (! $this->usageService->isInUse($media)) {
        $unused[] = [
          'id' => $media->uuid,
          'name' => $media->name,
          'url' => $media->url(),
          'size' => (int) $media->size,
        ];
      }
    }

    return $unused;
  }

  private function deleteVariantFiles(CmsMedia $media): void
  {
    $disk = Storage::disk($media->disk);
    if ($media->thumbnail_path && $disk->exists($media->thumbnail_path)) {
      $disk->delete($media->thumbnail_path);
    }

    $variants = $media->variants ?? [];
    foreach (['responsive', 'webp'] as $group) {
      foreach ($variants[$group] ?? [] as $variant) {
        $path = $variant['path'] ?? null;
        if (is_string($path) && $disk->exists($path)) {
          $disk->delete($path);
        }
      }
    }
  }

  private function deleteAllFiles(CmsMedia $media): void
  {
    $this->deleteVariantFiles($media);
    $disk = Storage::disk($media->disk);
    if ($disk->exists($media->path)) {
      $disk->delete($media->path);
    }
  }

  /**
   * @param  Collection<int, CmsMediaFolder>  $folders
   * @return Collection<int, CmsMediaFolder>
   */
  private function buildFolderTree(Collection $folders, ?int $parentId = null): Collection
  {
    return $folders
      ->where('parent_id', $parentId)
      ->values()
      ->map(function (CmsMediaFolder $folder) use ($folders): CmsMediaFolder {
        $folder->setRelation('children', $this->buildFolderTree($folders, $folder->id));

        return $folder;
      });
  }
}
