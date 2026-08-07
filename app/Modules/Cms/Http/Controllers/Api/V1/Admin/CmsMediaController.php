<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Exceptions\CmsMediaInUseException;
use App\Modules\Cms\Http\Resources\CmsMediaFolderResource;
use App\Modules\Cms\Http\Resources\CmsMediaResource;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMediaFolder;
use App\Modules\Cms\Services\CmsMediaAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CmsMediaController extends ApiController
{
  public function indexFolders(CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMediaFolder::class);

    return $this->responder->success(
      data: ['folders' => CmsMediaFolderResource::collection($service->folderTree())],
      message: 'Media folders retrieved.',
    );
  }

  public function storeFolder(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsMediaFolder::class);

    $validated = $request->validate([
      'name' => ['required', 'string', 'max:120'],
      'slug' => ['nullable', 'string', 'max:120', Rule::unique('cms_media_folders', 'slug')],
      'parent_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $folder = $service->createFolder($validated, $request->user());

    return $this->responder->success(
      data: ['folder' => new CmsMediaFolderResource($folder)],
      message: 'Media folder created.',
      status: 201,
    );
  }

  public function updateFolder(Request $request, CmsMediaFolder $folder, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('update', $folder);

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:120'],
      'slug' => ['sometimes', 'string', 'max:120', Rule::unique('cms_media_folders', 'slug')->ignore($folder->id)],
      'parent_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $folder = $service->updateFolder($folder, $validated, $request->user());

    return $this->responder->success(
      data: ['folder' => new CmsMediaFolderResource($folder)],
      message: 'Media folder updated.',
    );
  }

  public function destroyFolder(CmsMediaFolder $folder, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $folder);
    $service->deleteFolder($folder, $request->user());

    return $this->responder->success(message: 'Media folder deleted.');
  }

  public function index(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginateMedia($request->query()), CmsMediaResource::class),
      message: 'Media assets retrieved.',
    );
  }

  public function show(CmsMedia $media, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('view', $media);

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($service->show($media))],
      message: 'Media asset retrieved.',
    );
  }

  public function store(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsMedia::class);

    $validated = $request->validate([
      'file' => ['required', 'file', 'max:20480'],
      'folder_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
      'name' => ['nullable', 'string', 'max:120'],
    ]);

    $result = $service->upload(
      $request->file('file'),
      $request->user(),
      $validated['folder_id'] ?? null,
      $validated['name'] ?? null,
    );

    return $this->responder->success(
      data: [
        'media' => new CmsMediaResource($result['media']),
        'deduplicated' => $result['deduplicated'],
      ],
      message: $result['deduplicated'] ? 'Existing media reused (duplicate upload prevented).' : 'Media uploaded.',
      status: $result['deduplicated'] ? 200 : 201,
    );
  }

  public function bulkUpload(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsMedia::class);

    $validated = $request->validate([
      'files' => ['required', 'array', 'min:1', 'max:25'],
      'files.*' => ['file', 'max:20480'],
      'folder_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
    ]);

    $results = $service->bulkUpload(
      $request->file('files', []),
      $request->user(),
      $validated['folder_id'] ?? null,
    );

    return $this->responder->success(
      data: [
        'items' => collect($results)->map(fn (array $item) => [
          'media' => new CmsMediaResource($item['media']),
          'deduplicated' => $item['deduplicated'],
        ])->values(),
      ],
      message: 'Bulk upload completed.',
      status: 201,
    );
  }

  public function update(Request $request, CmsMedia $media, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('update', $media);

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:120'],
      'title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
      'caption' => ['sometimes', 'nullable', 'string', 'max:500'],
      'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
      'credits' => ['sometimes', 'nullable', 'string', 'max:255'],
      'copyright' => ['sometimes', 'nullable', 'string', 'max:255'],
      'tags' => ['sometimes', 'array'],
      'tags.*' => ['string', 'max:60'],
      'focal_x' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
      'focal_y' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
      'folder_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
    ]);

    $media = $service->updateMedia($media, $validated, $request->user());

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($media)],
      message: 'Media updated.',
    );
  }

  public function replace(Request $request, CmsMedia $media, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('update', $media);

    $request->validate([
      'file' => ['required', 'file', 'max:20480'],
    ]);

    $media = $service->replaceFile($media, $request->file('file'), $request->user());

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($media)],
      message: 'Media file replaced.',
    );
  }

  public function duplicate(CmsMedia $media, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $this->authorize('create', CmsMedia::class);

    $validated = $request->validate([
      'folder_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
    ]);

    $copy = $service->duplicateMedia($media, $request->user(), $validated['folder_id'] ?? null);

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($copy)],
      message: 'Media duplicated.',
      status: 201,
    );
  }

  public function crop(Request $request, CmsMedia $media, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('update', $media);

    $validated = $request->validate([
      'x' => ['required', 'integer', 'min:0'],
      'y' => ['required', 'integer', 'min:0'],
      'width' => ['required', 'integer', 'min:1'],
      'height' => ['required', 'integer', 'min:1'],
      'output_width' => ['nullable', 'integer', 'min:1', 'max:4096'],
      'replace' => ['nullable', 'boolean'],
    ]);

    $media = $service->cropMedia(
      $media,
      [
        'x' => $validated['x'],
        'y' => $validated['y'],
        'width' => $validated['width'],
        'height' => $validated['height'],
      ],
      $request->user(),
      (bool) ($validated['replace'] ?? false),
      (int) ($validated['output_width'] ?? 1280),
    );

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($media)],
      message: 'Media cropped.',
    );
  }

  public function resize(Request $request, CmsMedia $media, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('update', $media);

    $validated = $request->validate([
      'max_width' => ['required', 'integer', 'min:1', 'max:4096'],
      'max_height' => ['nullable', 'integer', 'min:0', 'max:4096'],
      'replace' => ['nullable', 'boolean'],
    ]);

    $media = $service->resizeMedia(
      $media,
      (int) $validated['max_width'],
      (int) ($validated['max_height'] ?? 0),
      $request->user(),
      (bool) ($validated['replace'] ?? false),
    );

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($media)],
      message: 'Media resized.',
    );
  }

  public function optimize(CmsMedia $media, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $media);
    $media = $service->optimizeMedia($media, $request->user());

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($media)],
      message: 'Media optimized.',
    );
  }

  public function destroy(CmsMedia $media, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $media);

    try {
      $service->deleteMedia($media, $request->user());
    } catch (CmsMediaInUseException $exception) {
      throw ValidationException::withMessages([
        'media' => ['Media asset is currently in use and cannot be deleted.'],
        'usages' => $exception->usages,
      ]);
    }

    return $this->responder->success(message: 'Media moved to recycle bin.');
  }

  public function restore(string $media, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $record = CmsMedia::onlyTrashed()->where('uuid', $media)->firstOrFail();
    $this->authorize('update', $record);
    $record = $service->restoreMedia($record, $request->user());

    return $this->responder->success(
      data: ['media' => new CmsMediaResource($record)],
      message: 'Media restored.',
    );
  }

  public function forceDestroy(string $media, CmsMediaAdminService $service, Request $request): JsonResponse
  {
    $record = CmsMedia::withTrashed()->where('uuid', $media)->firstOrFail();
    $this->authorize('delete', $record);

    try {
      $service->forceDeleteMedia($record, $request->user());
    } catch (CmsMediaInUseException $exception) {
      throw ValidationException::withMessages([
        'media' => ['Media asset is currently in use and cannot be permanently deleted.'],
        'usages' => $exception->usages,
      ]);
    }

    return $this->responder->success(message: 'Media permanently deleted.');
  }

  public function bulkDestroy(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    $validated = $request->validate([
      'media_ids' => ['required', 'array', 'min:1'],
      'media_ids.*' => ['string', 'exists:cms_media,uuid'],
    ]);

    try {
      $count = $service->bulkDeleteMedia($validated['media_ids'], $request->user());
    } catch (CmsMediaInUseException $exception) {
      throw ValidationException::withMessages([
        'media' => ['One or more media assets are in use and cannot be deleted.'],
        'usages' => $exception->usages,
      ]);
    }

    return $this->responder->success(
      data: ['deleted' => $count],
      message: 'Media assets moved to recycle bin.',
    );
  }

  public function bulkRestore(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    $validated = $request->validate([
      'media_ids' => ['required', 'array', 'min:1'],
      'media_ids.*' => ['string'],
    ]);

    $count = $service->bulkRestoreMedia($validated['media_ids'], $request->user());

    return $this->responder->success(
      data: ['restored' => $count],
      message: 'Media assets restored.',
    );
  }

  public function bulkForceDestroy(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    $validated = $request->validate([
      'media_ids' => ['required', 'array', 'min:1'],
      'media_ids.*' => ['string'],
    ]);

    try {
      $count = $service->bulkForceDeleteMedia($validated['media_ids'], $request->user());
    } catch (CmsMediaInUseException $exception) {
      throw ValidationException::withMessages([
        'media' => ['One or more media assets are in use and cannot be permanently deleted.'],
        'usages' => $exception->usages,
      ]);
    }

    return $this->responder->success(
      data: ['deleted' => $count],
      message: 'Media assets permanently deleted.',
    );
  }

  public function bulkMove(Request $request, CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    $validated = $request->validate([
      'media_ids' => ['required', 'array', 'min:1'],
      'media_ids.*' => ['string', 'exists:cms_media,uuid'],
      'folder_id' => ['nullable', 'string', 'exists:cms_media_folders,uuid'],
    ]);

    $count = $service->bulkMoveMedia(
      $validated['media_ids'],
      $validated['folder_id'] ?? null,
      $request->user(),
    );

    return $this->responder->success(
      data: ['moved' => $count],
      message: 'Media assets moved.',
    );
  }

  public function statistics(CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    return $this->responder->success(
      data: ['statistics' => $service->storageStatistics()],
      message: 'Media storage statistics retrieved.',
    );
  }

  public function broken(CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    return $this->responder->success(
      data: ['items' => $service->detectBrokenMedia()],
      message: 'Broken media scan completed.',
    );
  }

  public function unused(CmsMediaAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMedia::class);

    return $this->responder->success(
      data: ['items' => $service->detectUnusedMedia()],
      message: 'Unused media scan completed.',
    );
  }
}
