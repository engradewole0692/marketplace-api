<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends ApiController
{
  public function overview(Request $request, DashboardMetricsService $service): JsonResponse
  {
    $user = $request->user();
    abort_unless($user !== null && $user->hasPermission('admin.access'), 403);

    return $this->responder->success(
      data: $service->overview(),
      message: 'Dashboard overview retrieved.',
    );
  }

  public function activity(Request $request, DashboardMetricsService $service): JsonResponse
  {
    $user = $request->user();
    abort_unless($user !== null && $user->hasPermission('admin.access'), 403);

    $limit = min(max((int) $request->query('limit', 20), 1), 50);
    $offset = max((int) $request->query('offset', 0), 0);

    $feed = $service->activityFeed($limit, $offset);

    return $this->responder->success(
      data: [
        'activity' => $feed['items'],
        'activity_meta' => $feed['meta'],
      ],
      message: 'Activity feed retrieved.',
    );
  }

  public function search(Request $request, GlobalSearchService $service): JsonResponse
  {
    $user = $request->user();
    abort_unless($user !== null && $user->hasPermission('admin.access'), 403);

    $validated = $request->validate([
      'q' => ['required', 'string', 'min:2', 'max:120'],
      'per_group' => ['sometimes', 'integer', 'min:1', 'max:10'],
    ]);

    return $this->responder->success(
      data: $service->search(
        (string) $validated['q'],
        (int) ($validated['per_group'] ?? 5),
      ),
      message: 'Search results retrieved.',
    );
  }
}
