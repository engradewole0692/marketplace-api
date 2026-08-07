<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Services\CourseCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseCommerceAdminController extends ApiController
{
  public function index(Request $request, CourseCommerceService $commerce): JsonResponse
  {
    $this->authorize('viewAny', CourseOrder::class);
    $paginator = $commerce->paginateOrders($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (CourseOrder $o) => $commerce->orderPayload($o)),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Course orders retrieved.',
    );
  }

  public function show(CourseOrder $order, CourseCommerceService $commerce): JsonResponse
  {
    $this->authorize('view', $order);

    return $this->responder->success(
      data: ['order' => $commerce->orderPayload($order)],
      message: 'Course order retrieved.',
    );
  }

  public function confirm(Request $request, CourseOrder $order, CourseCommerceService $commerce): JsonResponse
  {
    $this->authorize('confirm', CourseOrder::class);
    $order = $commerce->confirmOffline($order, $request->user());

    return $this->responder->success(
      data: ['order' => $commerce->orderPayload($order)],
      message: 'Offline payment confirmed. Enrollment activated.',
    );
  }

  public function refund(Request $request, CourseOrder $order, CourseCommerceService $commerce): JsonResponse
  {
    $this->authorize('refund', CourseOrder::class);
    $validated = $request->validate([
      'amount' => ['nullable', 'numeric', 'min:0.01'],
      'reason' => ['nullable', 'string', 'max:500'],
    ]);

    $result = $commerce->refund(
      $order,
      $request->user(),
      isset($validated['amount']) ? (float) $validated['amount'] : null,
      $validated['reason'] ?? null,
    );

    return $this->responder->success(
      data: [
        'order' => $commerce->orderPayload($result['order']),
        'refund' => [
          'id' => $result['refund']->uuid,
          'amount' => (float) $result['refund']->amount,
          'status' => $result['refund']->status instanceof \BackedEnum
            ? $result['refund']->status->value
            : $result['refund']->status,
          'gateway_refunded' => $result['refund']->gateway_refunded,
        ],
      ],
      message: 'Refund processed.',
    );
  }
}
