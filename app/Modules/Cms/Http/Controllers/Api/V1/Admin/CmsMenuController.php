<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsMenuResource;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Services\CmsMenuAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsMenuController extends ApiController
{
  public function index(CmsMenuAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsMenu::class);

    return $this->responder->success(
      data: ['menus' => CmsMenuResource::collection($service->all())],
      message: 'Menus retrieved.',
    );
  }

  public function show(CmsMenu $menu, CmsMenuAdminService $service): JsonResponse
  {
    $this->authorize('view', $menu);

    return $this->responder->success(
      data: ['menu' => new CmsMenuResource($service->show($menu))],
      message: 'Menu retrieved.',
    );
  }

  public function update(Request $request, CmsMenu $menu, CmsMenuAdminService $service): JsonResponse
  {
    $this->authorize('update', $menu);

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:120'],
      'is_active' => ['sometimes', 'boolean'],
    ]);

    $menu = $service->update($menu, $validated, $request->user());

    return $this->responder->success(
      data: ['menu' => new CmsMenuResource($menu)],
      message: 'Menu updated.',
    );
  }

  public function syncItems(Request $request, CmsMenu $menu, CmsMenuAdminService $service): JsonResponse
  {
    $this->authorize('update', $menu);

    $validated = $request->validate([
      'items' => ['required', 'array'],
      'items.*.label' => ['required', 'string', 'max:255'],
      'items.*.url' => ['nullable', 'string', 'max:500'],
      'items.*.route_name' => ['nullable', 'string', 'max:255'],
      'items.*.icon' => ['nullable', 'string', 'max:64'],
      'items.*.open_in_new_tab' => ['sometimes', 'boolean'],
      'items.*.is_active' => ['sometimes', 'boolean'],
      'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
      'items.*.children' => ['sometimes', 'array'],
    ]);

    $menu = $service->syncItems($menu, $validated['items'], $request->user());

    return $this->responder->success(
      data: ['menu' => new CmsMenuResource($menu)],
      message: 'Menu items updated.',
    );
  }
}
