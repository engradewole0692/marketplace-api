<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Services\CmsDashboardService;
use App\Modules\Cms\Support\CmsPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsDashboardController extends ApiController
{
  public function overview(Request $request, CmsDashboardService $service): JsonResponse
  {
    abort_unless(CmsPermission::allows($request->user(), 'cms.pages.view'), 403);

    return $this->responder->success(
      data: ['overview' => $service->overview()],
      message: 'CMS dashboard overview retrieved.',
    );
  }
}
