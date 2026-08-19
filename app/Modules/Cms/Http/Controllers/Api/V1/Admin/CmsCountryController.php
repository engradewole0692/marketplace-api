<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsCountryResource;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Services\CmsCountryAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsCountryController extends ApiController
{
  public function index(Request $request, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsCountry::class);

    $paginator = $service->paginate($request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, CmsCountryResource::class),
      message: 'Countries retrieved.',
    );
  }

  public function store(Request $request, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsCountry::class);

    $validated = $this->validateCountry($request);

    $country = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['country' => new CmsCountryResource($country)],
      message: 'Country created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsCountry $country, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('update', $country);

    $validated = $this->validateCountry($request, $country->id, true);

    $country = $service->update($country, $validated, $request->user());

    return $this->responder->success(
      data: ['country' => new CmsCountryResource($country)],
      message: 'Country updated.',
    );
  }

  public function uploadImage(Request $request, CmsCountry $country, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('update', $country);
    $validated = $request->validate([
      'image' => ['required', 'image', 'max:5120'],
    ]);

    $country = $service->uploadImage($country, $validated['image'], $request->user());

    return $this->responder->success(
      data: ['country' => new CmsCountryResource($country->load('heroMedia'))],
      message: 'Country image updated.',
    );
  }

  public function destroy(Request $request, CmsCountry $country, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('delete', $country);
    $service->delete($country, $request->user());

    return $this->responder->success(message: 'Country deleted.');
  }

  public function reorder(Request $request, CmsCountryAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsCountry::class);
    $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string']]);
    $service->reorder($validated['ids'], $request->user());

    return $this->responder->success(message: 'Country order updated.');
  }

  private function validateCountry(Request $request, ?int $countryId = null, bool $isUpdate = false): array
  {
    return $request->validate([
      'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('cms_countries', 'slug')->ignore($countryId)],
      'code' => ['nullable', 'string', 'max:8'],
      'region' => ['nullable', 'string', 'max:255'],
      'flag_emoji' => ['nullable', 'string', 'max:16'],
      'latitude' => ['nullable', 'numeric'],
      'longitude' => ['nullable', 'numeric'],
      'launched_year' => ['nullable', 'string', 'max:16'],
      'summary' => ['nullable', 'string'],
      'content' => ['nullable', 'array'],
      'hero_media_id' => ['nullable'],
      'primary_leader_id' => ['nullable', 'string'],
      'phone' => ['nullable', 'string', 'max:40'],
      'whatsapp_number' => ['nullable', 'string', 'max:40'],
      'office_address' => ['nullable', 'string', 'max:500'],
      'office_hours' => ['nullable', 'string', 'max:255'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);
  }
}
