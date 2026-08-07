<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Requests\Admin\PublishCmsPageSectionRequest;
use App\Modules\Cms\Http\Requests\Admin\ReorderCmsPageSectionsRequest;
use App\Modules\Cms\Http\Requests\Admin\UpdateCmsPageSectionRequest;
use App\Modules\Cms\Http\Resources\CmsPageSectionResource;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsPageSectionVersion;
use App\Modules\Cms\Services\CmsPageSectionAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsPageSectionController extends ApiController
{
  public function index(Request $request, CmsPageSectionAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPageSection::class);

    return $this->responder->success(
      data: CmsPageSectionResource::collection($service->forPage($request->query('page_slug'))),
      message: 'Page sections retrieved.',
    );
  }

  public function update(
    UpdateCmsPageSectionRequest $request,
    CmsPageSection $section,
    CmsPageSectionAdminService $service,
  ): JsonResponse {
    $this->authorize('update', $section);

    $section = $service->update($section, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['section' => new CmsPageSectionResource($section)],
      message: 'Page section draft saved.',
    );
  }

  public function reorder(ReorderCmsPageSectionsRequest $request, CmsPageSectionAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPageSection::class);

    $sections = $service->reorder($request->validated()['sections'], $request->user());

    return $this->responder->success(
      data: CmsPageSectionResource::collection($sections),
      message: 'Page sections reordered.',
    );
  }

  public function submitReview(
    Request $request,
    CmsPageSection $section,
    CmsPageSectionAdminService $service,
  ): JsonResponse {
    $this->authorize('update', $section);
    $section = $service->submitForReview($section, $request->user());

    return $this->responder->success(
      data: ['section' => new CmsPageSectionResource($section)],
      message: 'Section submitted for review.',
    );
  }

  public function publish(
    PublishCmsPageSectionRequest $request,
    CmsPageSection $section,
    CmsPageSectionAdminService $service,
  ): JsonResponse {
    $this->authorize('update', $section);

    $validated = $request->validated();

    if (array_key_exists('draft_content', $validated)) {
      $section = $service->update($section, [
        'draft_content' => $validated['draft_content'],
        'status' => 'draft',
      ], $request->user());
    }

    $section = $service->publish($section, $request->user(), $validated['change_summary'] ?? null);

    return $this->responder->success(
      data: ['section' => new CmsPageSectionResource($section->load('versions'))],
      message: 'Section published.',
    );
  }

  public function versions(CmsPageSection $section): JsonResponse
  {
    $this->authorize('update', $section);

    $section->load('versions');

    return $this->responder->success(
      data: ['section' => new CmsPageSectionResource($section)],
      message: 'Section versions retrieved.',
    );
  }

  public function restoreVersion(
    Request $request,
    CmsPageSection $section,
    CmsPageSectionVersion $version,
    CmsPageSectionAdminService $service,
  ): JsonResponse {
    $this->authorize('update', $section);

    abort_unless($version->section_id === $section->id, 404);

    $section = $service->restoreVersion($section, $version, $request->user());

    return $this->responder->success(
      data: ['section' => new CmsPageSectionResource($section->load('versions'))],
      message: 'Section version restored to draft.',
    );
  }
}
