<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsSeoResource;
use App\Modules\Cms\Models\CmsSeo;
use App\Modules\Cms\Services\CmsSeoAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsSeoController extends ApiController
{
  public function index(Request $request, CmsSeoAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsSeo::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsSeoResource::class),
      message: 'SEO records retrieved.',
    );
  }

  public function store(Request $request, CmsSeoAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsSeo::class);
    $seo = $service->create($this->validateSeo($request), $request->user());

    return $this->responder->success(
      data: ['seo' => new CmsSeoResource($seo)],
      message: 'SEO record created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsSeo $seo, CmsSeoAdminService $service): JsonResponse
  {
    $this->authorize('update', $seo);
    $seo = $service->update($seo, $this->validateSeo($request, true), $request->user());

    return $this->responder->success(
      data: ['seo' => new CmsSeoResource($seo)],
      message: 'SEO record updated.',
    );
  }

  public function destroy(Request $request, CmsSeo $seo, CmsSeoAdminService $service): JsonResponse
  {
    $this->authorize('delete', $seo);
    $service->delete($seo, $request->user());

    return $this->responder->success(message: 'SEO record deleted.');
  }

  private function validateSeo(Request $request, bool $partial = false): array
  {
    return $request->validate([
      'path' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'entity_type' => ['nullable', 'string', 'max:64'],
      'entity_id' => ['nullable', 'integer'],
      'meta_title' => ['nullable', 'string', 'max:70'],
      'meta_description' => ['nullable', 'string', 'max:160'],
      'meta_keywords' => ['nullable', 'string', 'max:255'],
      'canonical_url' => ['nullable', 'url'],
      'og_title' => ['nullable', 'string', 'max:70'],
      'og_description' => ['nullable', 'string', 'max:200'],
      'og_image_id' => ['nullable', 'string'],
      'twitter_card' => ['nullable', 'string', Rule::in(['summary', 'summary_large_image'])],
      'json_ld' => ['nullable', 'array'],
      'no_index' => ['sometimes', 'boolean'],
      'robots' => ['nullable', 'string', 'max:64'],
    ]);
  }
}
