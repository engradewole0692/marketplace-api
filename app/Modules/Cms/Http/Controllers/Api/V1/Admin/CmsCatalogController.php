<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Http\Resources\CmsCatalogItemResource;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Services\CmsCatalogAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsCatalogController extends ApiController
{
  public function index(string $type, Request $request, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsCatalogItem::class);
    $catalogType = CatalogItemType::from($type);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginate($catalogType, $request->query()),
        CmsCatalogItemResource::class,
      ),
      message: 'Catalog items retrieved.',
    );
  }

  public function store(string $type, Request $request, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsCatalogItem::class);
    $catalogType = CatalogItemType::from($type);
    $validated = $this->validateItem($request, $catalogType);
    $item = $service->create($catalogType, $validated, $request->user());

    return $this->responder->success(
      data: ['item' => new CmsCatalogItemResource($item)],
      message: 'Catalog item created.',
      status: 201,
    );
  }

  public function update(string $type, Request $request, CmsCatalogItem $item, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('update', $item);
    abort_unless($item->type->value === $type, 404);
    $validated = $this->validateItem($request, $item->type, $item->id, true);
    $item = $service->update($item, $validated, $request->user());

    return $this->responder->success(
      data: ['item' => new CmsCatalogItemResource($item)],
      message: 'Catalog item updated.',
    );
  }

  public function destroy(string $type, Request $request, CmsCatalogItem $item, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('delete', $item);
    abort_unless($item->type->value === $type, 404);
    $service->delete($item, $request->user());

    return $this->responder->success(message: 'Catalog item deleted.');
  }

  public function uploadMedia(string $type, Request $request, CmsCatalogItem $item, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('update', $item);
    abort_unless($item->type->value === $type, 404);

    $validated = $request->validate([
      'media' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
    ]);

    $item = $service->uploadFeaturedMedia($item, $validated['media'], $request->user());

    return $this->responder->success(
      data: ['item' => new CmsCatalogItemResource($item)],
      message: 'Catalog media uploaded.',
    );
  }

  public function uploadResourceFile(string $type, Request $request, CmsCatalogItem $item, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('update', $item);
    abort_unless($type === CatalogItemType::Resource->value && $item->type === CatalogItemType::Resource, 404);

    $validated = $request->validate([
      'file' => ['required', 'file', 'max:102400'],
    ]);

    $item = $service->uploadResourceFile($item, $validated['file'], $request->user());

    return $this->responder->success(
      data: ['item' => new CmsCatalogItemResource($item)],
      message: 'Resource file uploaded.',
    );
  }

  public function reorder(string $type, Request $request, CmsCatalogAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsCatalogItem::class);
    $catalogType = CatalogItemType::from($type);

    $validated = $request->validate([
      'ids' => ['required', 'array'],
      'ids.*' => ['required', 'string'],
    ]);

    $service->reorder($catalogType, $validated['ids'], $request->user());

    return $this->responder->success(message: 'Catalog items reordered.');
  }

  private function validateItem(Request $request, CatalogItemType $type, ?int $itemId = null, bool $isUpdate = false): array
  {
    return $request->validate([
      'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255', Rule::unique('cms_catalog_items', 'slug')->where('type', $type->value)->ignore($itemId)],
      'summary' => ['nullable', 'string'],
      'body' => ['nullable', 'string'],
      'metadata' => ['nullable', 'array'],
      'category' => ['nullable', 'string', 'max:120'],
      'tags' => ['nullable', 'array'],
      'featured_media_id' => ['nullable'],
      'is_active' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'published_at' => ['nullable', 'date'],
    ]);
  }
}
