<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsSettingResource;
use App\Modules\Cms\Models\CmsSetting;
use App\Modules\Cms\Services\CmsSettingAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsSettingController extends ApiController
{
  public function index(Request $request, CmsSettingAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsSetting::class);

    return $this->responder->success(
      data: CmsSettingResource::collection($service->all($request->query('group'))),
      message: 'Settings retrieved.',
    );
  }

  public function bulkUpdate(Request $request, CmsSettingAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsSetting::class);

    $validated = $request->validate([
      'settings' => ['required', 'array', 'min:1'],
      'settings.*.key' => ['required', 'string', 'max:255'],
      'settings.*.value' => ['present'],
      'settings.*.group' => ['nullable', 'string', 'max:64'],
      'settings.*.is_public' => ['nullable', 'boolean'],
    ]);

    $settings = $service->bulkUpdate($validated['settings'], $request->user());

    return $this->responder->success(
      data: CmsSettingResource::collection($settings),
      message: 'Settings updated.',
    );
  }
}
