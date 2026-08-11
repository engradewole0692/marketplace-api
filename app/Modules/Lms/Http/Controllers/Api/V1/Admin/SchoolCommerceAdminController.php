<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\SchoolOrder;
use App\Modules\Lms\Services\SchoolCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SchoolCommerceAdminController extends ApiController
{
  public function index(Request $request, SchoolCommerceService $commerce): JsonResponse
  {
    $this->authorize('viewAny', SchoolOrder::class);
    $paginator = $commerce->paginateOrders($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (SchoolOrder $o) => $commerce->orderPayload($o)),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'School orders retrieved.',
    );
  }

  public function show(SchoolOrder $order, SchoolCommerceService $commerce): JsonResponse
  {
    $this->authorize('view', $order);

    return $this->responder->success(
      data: ['order' => $commerce->orderPayload($order)],
      message: 'School order retrieved.',
    );
  }

  public function confirm(Request $request, SchoolOrder $order, SchoolCommerceService $commerce): JsonResponse
  {
    $this->authorize('confirm', SchoolOrder::class);
    $order = $commerce->confirmOffline($order, $request->user());

    return $this->responder->success(
      data: ['order' => $commerce->orderPayload($order)],
      message: 'Offline payment confirmed. School enrollment activated.',
    );
  }

  public function reject(Request $request, SchoolOrder $order, SchoolCommerceService $commerce): JsonResponse
  {
    $this->authorize('reject', SchoolOrder::class);
    $validated = $request->validate([
      'reason' => ['nullable', 'string', 'max:500'],
    ]);

    $order = $commerce->rejectOffline($order, $request->user(), $validated['reason'] ?? null);

    return $this->responder->success(
      data: ['order' => $commerce->orderPayload($order)],
      message: 'Offline payment rejected.',
    );
  }
}
