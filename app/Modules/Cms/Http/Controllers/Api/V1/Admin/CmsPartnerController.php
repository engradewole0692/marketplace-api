<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsPartnerResource;
use App\Modules\Cms\Models\CmsPartner;
use App\Modules\Cms\Services\CmsPartnerAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsPartnerController extends ApiController
{
  public function index(Request $request, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPartner::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsPartnerResource::class),
      message: 'Partners retrieved.',
    );
  }

  public function store(Request $request, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsPartner::class);
    $partner = $service->create($this->validatePartner($request), $request->user());

    return $this->responder->success(
      data: ['partner' => new CmsPartnerResource($partner)],
      message: 'Partner created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsPartner $partner, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('update', $partner);
    $partner = $service->update($partner, $this->validatePartner($request, $partner->id), $request->user());

    return $this->responder->success(
      data: ['partner' => new CmsPartnerResource($partner)],
      message: 'Partner updated.',
    );
  }

  public function destroy(Request $request, CmsPartner $partner, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('delete', $partner);
    $service->delete($partner, $request->user());

    return $this->responder->success(message: 'Partner deleted.');
  }

  public function reorder(Request $request, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPartner::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_partners,uuid'],
    ]);

    $service->reorder($validated['ids'], $request->user());

    return $this->responder->success(message: 'Partners reordered.');
  }

  public function bulkUpdate(Request $request, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPartner::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_partners,uuid'],
      'is_active' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
    ]);

    $count = $service->bulkUpdate($validated['ids'], $validated, $request->user());

    return $this->responder->success(
      data: ['updated' => $count],
      message: 'Partners updated.',
    );
  }

  public function bulkDestroy(Request $request, CmsPartnerAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPartner::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_partners,uuid'],
    ]);

    $count = $service->bulkDelete($validated['ids'], $request->user());

    return $this->responder->success(
      data: ['deleted' => $count],
      message: 'Partners deleted.',
    );
  }

  private function validatePartner(Request $request, ?int $partnerId = null): array
  {
    return $request->validate([
      'name' => [$partnerId ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255', Rule::unique('cms_partners', 'slug')->ignore($partnerId)],
      'country_id' => ['nullable', 'string'],
      'tier' => ['nullable', 'string', 'max:64'],
      'website_url' => ['nullable', 'url'],
      'donation_url' => ['nullable', 'url'],
      'description' => ['nullable', 'string'],
      'logo_media_id' => ['nullable', 'string'],
      'is_featured' => ['sometimes', 'boolean'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);
  }
}
