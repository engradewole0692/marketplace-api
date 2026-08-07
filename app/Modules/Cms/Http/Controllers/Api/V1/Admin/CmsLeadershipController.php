<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsLeadershipResource;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Services\CmsLeadershipAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsLeadershipController extends ApiController
{
  public function index(Request $request, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsLeadershipProfile::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsLeadershipResource::class),
      message: 'Leadership profiles retrieved.',
    );
  }

  public function store(Request $request, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsLeadershipProfile::class);
    $validated = $this->validateProfile($request);
    $profile = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['profile' => new CmsLeadershipResource($profile)],
      message: 'Leadership profile created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsLeadershipProfile $profile, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('update', $profile);
    $validated = $this->validateProfile($request, $profile->id, true);
    $profile = $service->update($profile, $validated, $request->user());

    return $this->responder->success(
      data: ['profile' => new CmsLeadershipResource($profile->load(['country', 'ministry', 'photoMedia']))],
      message: 'Leadership profile updated.',
    );
  }

  public function destroy(Request $request, CmsLeadershipProfile $profile, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('delete', $profile);
    $service->delete($profile, $request->user());

    return $this->responder->success(message: 'Leadership profile deleted.');
  }

  public function uploadPhoto(Request $request, CmsLeadershipProfile $profile, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('update', $profile);
    $validated = $request->validate([
      'photo' => ['required', 'image', 'max:5120'],
    ]);

    $profile = $service->uploadPhoto($profile, $validated['photo'], $request->user());

    return $this->responder->success(
      data: ['profile' => new CmsLeadershipResource($profile->load(['country', 'ministry', 'photoMedia']))],
      message: 'Leadership photo updated.',
    );
  }

  public function reorder(Request $request, CmsLeadershipAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsLeadershipProfile::class);
    $validated = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string']]);
    $service->reorder($validated['ids'], $request->user());

    return $this->responder->success(message: 'Leadership order updated.');
  }

  private function validateProfile(Request $request, ?int $profileId = null, bool $isUpdate = false): array
  {
    return $request->validate([
      'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('cms_leadership_profiles', 'slug')->ignore($profileId)],
      'role' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
      'hierarchy_level' => ['nullable', 'string', 'max:60'],
      'category' => ['nullable', 'string', 'max:64'],
      'location' => ['nullable', 'string', 'max:255'],
      'state' => ['nullable', 'string', 'max:120'],
      'bio' => ['nullable', 'string'],
      'email' => ['nullable', 'email'],
      'phone' => ['nullable', 'string', 'max:64'],
      'social_links' => ['nullable', 'array'],
      'term_start' => ['nullable', 'date'],
      'term_end' => ['nullable', 'date'],
      'visibility' => ['nullable', 'string', 'max:40'],
      'permissions' => ['nullable', 'array'],
      'member_id' => ['nullable', 'integer', 'exists:members,id'],
      'country_id' => ['nullable', 'integer', 'exists:cms_countries,id'],
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'photo_media_id' => ['nullable'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);
  }
}
