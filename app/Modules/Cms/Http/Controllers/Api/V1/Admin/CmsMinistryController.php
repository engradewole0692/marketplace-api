<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsMinistryResource;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Cms\Services\CmsMinistryAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsMinistryController extends ApiController
{
  public function index(Request $request, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMinistry::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsMinistryResource::class),
      message: 'Ministries retrieved.',
    );
  }

  public function store(Request $request, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsMinistry::class);
    $validated = $this->validateMinistry($request);
    $ministry = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['ministry' => new CmsMinistryResource($ministry)],
      message: 'Ministry created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsMinistry $ministry, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('update', $ministry);
    $validated = $this->validateMinistry($request, $ministry->id, true);
    $ministry = $service->update($ministry, $validated, $request->user());

    return $this->responder->success(
      data: ['ministry' => new CmsMinistryResource($ministry)],
      message: 'Ministry updated.',
    );
  }

  public function uploadImage(Request $request, CmsMinistry $ministry, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('update', $ministry);
    $validated = $request->validate([
      'image' => ['required', 'image', 'max:5120'],
    ]);

    $ministry = $service->uploadImage($ministry, $validated['image'], $request->user());

    return $this->responder->success(
      data: ['ministry' => new CmsMinistryResource($ministry->load('heroMedia'))],
      message: 'Ministry image updated.',
    );
  }

  public function destroy(Request $request, CmsMinistry $ministry, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('delete', $ministry);
    $service->delete($ministry, $request->user());

    return $this->responder->success(message: 'Ministry deleted.');
  }

  public function reorder(Request $request, CmsMinistryAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMinistry::class);
    $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string']]);
    $service->reorder($validated['ids'], $request->user());

    return $this->responder->success(message: 'Ministry order updated.');
  }

  private function validateMinistry(Request $request, ?int $ministryId = null, bool $isUpdate = false): array
  {
    return $request->validate([
      'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('cms_ministries', 'slug')->ignore($ministryId)],
      'icon' => ['nullable', 'string', 'max:64'],
      'color' => ['nullable', 'string', 'max:32'],
      'tagline' => ['nullable', 'string', 'max:255'],
      'summary' => ['nullable', 'string'],
      'about' => ['nullable', 'string'],
      'mission' => ['nullable', 'string'],
      'vision' => ['nullable', 'string'],
      'purposes' => ['nullable', 'array'],
      'programs' => ['nullable', 'array'],
      'content' => ['nullable', 'array'],
      'hero_media_id' => ['nullable'],
      'logo_media_id' => ['nullable'],
      'banner_media_id' => ['nullable'],
      'cover_media_id' => ['nullable'],
      'visibility' => ['nullable', 'string', 'max:40'],
      'operational_status' => ['nullable', 'string', 'max:40'],
      'leader_member_id' => ['nullable', 'integer', 'exists:members,id'],
      'assistant_leader_member_id' => ['nullable', 'integer', 'exists:members,id'],
      'whatsapp_link' => ['nullable', 'string', 'max:500'],
      'telegram_link' => ['nullable', 'string', 'max:500'],
      'signal_link' => ['nullable', 'string', 'max:500'],
      'country_availability' => ['nullable', 'array'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);
  }
}
