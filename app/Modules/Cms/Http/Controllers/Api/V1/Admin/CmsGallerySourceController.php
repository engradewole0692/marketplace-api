<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsGallerySourceResource;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsGallerySource;
use App\Modules\Cms\Services\CmsGallerySourceAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsGallerySourceController extends ApiController
{
  public function index(CmsGallerySourceAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsCatalogItem::class);

    return $this->responder->success(
      data: CmsGallerySourceResource::collection($service->all())->resolve(),
      message: 'Gallery sources retrieved.',
    );
  }

  public function update(
    Request $request,
    CmsGallerySource $source,
    CmsGallerySourceAdminService $service,
  ): JsonResponse {
    $this->authorize('viewAny', CmsCatalogItem::class);
    $validated = $request->validate([
      'is_enabled' => ['sometimes', 'boolean'],
      'name' => ['sometimes', 'string', 'max:255'],
    ]);

    $source = $service->update($source, $validated, $request->user());

    return $this->responder->success(
      data: ['source' => (new CmsGallerySourceResource($source))->resolve()],
      message: 'Gallery source updated.',
    );
  }

  public function sync(
    Request $request,
    CmsGallerySource $source,
    CmsGallerySourceAdminService $service,
  ): JsonResponse {
    $this->authorize('viewAny', CmsCatalogItem::class);
    $result = $service->syncNow($source->fresh() ?? $source, $request->user());
    $source->refresh();

    return $this->responder->success(
      data: [
        'source' => (new CmsGallerySourceResource($source))->resolve(),
        'result' => $result,
      ],
      message: 'Gallery source sync finished.',
    );
  }
}
