<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreEventCategoryRequest;
use App\Modules\Events\Http\Resources\EventCategoryResource;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Models\EventCategory;
use App\Modules\Events\Services\EventCategoryService;
use App\Modules\Events\Support\UuidResolver;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventCategoryAdminController extends ApiController
{
  public function index(Request $request, EventCategoryService $service): JsonResponse
  {
    $this->authorize('viewAny', EventCategory::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EventCategoryResource::class),
      message: 'Event categories retrieved.',
    );
  }

  public function store(StoreEventCategoryRequest $request, EventCategoryService $service): JsonResponse
  {
    $this->authorize('create', EventCategory::class);

    $category = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['category' => new EventCategoryResource($category)],
      message: 'Event category created.',
      status: 201,
    );
  }

  public function update(Request $request, EventCategory $category, EventCategoryService $service): JsonResponse
  {
    $this->authorize('update', $category);

    UuidResolver::resolve($request, ['ministry_id' => CmsMinistry::class]);

    $validated = $request->validate([
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'status' => ['nullable', 'string', 'max:40'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $category = $service->update($category, $validated, $request->user());

    return $this->responder->success(
      data: ['category' => new EventCategoryResource($category)],
      message: 'Event category updated.',
    );
  }

  public function destroy(EventCategory $category, EventCategoryService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $category);

    $service->delete($category, $request->user());

    return $this->responder->success(data: null, message: 'Event category deleted.');
  }
}
