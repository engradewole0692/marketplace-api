<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsPageResource;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageVersion;
use App\Modules\Cms\Services\CmsPageAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsPageController extends ApiController
{
  public function index(Request $request, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsPage::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsPageResource::class),
      message: 'Pages retrieved.',
    );
  }

  public function store(Request $request, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsPage::class);
    $validated = $this->validatePage($request);
    $page = $service->create($validated, $request->user(), $request->input('change_summary'));

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page created.',
      status: 201,
    );
  }

  public function show(CmsPage $page): JsonResponse
  {
    $this->authorize('view', $page);
    $page->loadMissing('heroMedia');

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page retrieved.',
    );
  }

  public function update(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('update', $page);
    $validated = $this->validatePage($request, $page->id);
    $page = $service->update($page, $validated, $request->user(), $request->input('change_summary'));
    $page->loadMissing('heroMedia');

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page updated.',
    );
  }

  public function destroy(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('delete', $page);
    $service->delete($page, $request->user());

    return $this->responder->success(message: 'Page deleted.');
  }

  public function versions(CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('view', $page);

    return $this->responder->success(
      data: $service->versions($page)->map(fn (CmsPageVersion $v) => [
        'id' => $v->uuid,
        'version_number' => $v->version_number,
        'title' => $v->title,
        'status' => $v->status,
        'change_summary' => $v->change_summary,
        'created_at' => $v->created_at?->toIso8601String(),
      ]),
      message: 'Page versions retrieved.',
    );
  }

  public function restoreVersion(Request $request, CmsPage $page, CmsPageVersion $version, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('update', $page);
    abort_unless($version->page_id === $page->id, 404);
    $page = $service->restoreVersion($page, $version, $request->user());

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page version restored.',
    );
  }

  public function compareVersions(CmsPage $page, CmsPageVersion $from, CmsPageVersion $to, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('view', $page);
    abort_unless($from->page_id === $page->id && $to->page_id === $page->id, 404);

    return $this->responder->success(
      data: $service->compareVersions($from, $to),
      message: 'Version comparison retrieved.',
    );
  }

  public function publish(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('publish', $page);

    $validated = $request->validate([
      'scheduled_at' => ['nullable', 'date', 'after:now'],
    ]);

    $page = $service->publish($page, $request->user(), $validated['scheduled_at'] ?? null);

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page published.',
    );
  }

  public function unpublish(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('publish', $page);
    $page = $service->unpublish($page, $request->user());

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page unpublished.',
    );
  }

  public function archive(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('update', $page);
    $page = $service->archive($page, $request->user());

    return $this->responder->success(
      data: ['page' => new CmsPageResource($page)],
      message: 'Page archived.',
    );
  }

  public function duplicate(Request $request, CmsPage $page, CmsPageAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsPage::class);
    $duplicate = $service->duplicate($page, $request->user());

    return $this->responder->success(
      data: ['page' => new CmsPageResource($duplicate)],
      message: 'Page duplicated.',
      status: 201,
    );
  }

  private function validatePage(Request $request, ?int $pageId = null): array
  {
    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255', Rule::unique('cms_pages', 'slug')->ignore($pageId)],
      'status' => ['nullable', Rule::in(['draft', 'review', 'published', 'scheduled', 'archived'])],
      'hero_title' => ['nullable', 'string', 'max:255'],
      'hero_subtitle' => ['nullable', 'string'],
      'hero_media_id' => ['nullable'],
      'blocks' => ['nullable', 'array'],
      'published_at' => ['nullable', 'date'],
      'scheduled_at' => ['nullable', 'date'],
      'change_summary' => ['nullable', 'string', 'max:500'],
    ]);

    if (array_key_exists('hero_media_id', $validated) && $validated['hero_media_id'] !== null && $validated['hero_media_id'] !== '') {
      $mediaId = $validated['hero_media_id'];
      if (! is_numeric($mediaId)) {
        $resolved = \App\Modules\Cms\Models\CmsMedia::query()->where('uuid', $mediaId)->value('id');
        $validated['hero_media_id'] = $resolved;
      } else {
        $validated['hero_media_id'] = (int) $mediaId;
      }
    } elseif (array_key_exists('hero_media_id', $validated)) {
      $validated['hero_media_id'] = null;
    }

    return $validated;
  }
}
