<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Learner;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\CourseCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LearnerCommerceController extends ApiController
{
  public function checkout(Request $request, Enrollment $enrollment, CourseCommerceService $commerce): JsonResponse
  {
    abort_unless($enrollment->user_id === $request->user()->id, 403);

    $validated = $request->validate([
      'payment_method' => ['required', 'string', Rule::in([
        'paystack', 'flutterwave', 'stripe', 'card', 'offline', 'bank_account', 'wire', 'paypal', 'crypto',
      ])],
      'country' => ['nullable', 'string', 'max:80'],
      'country_id' => ['nullable', 'uuid'],
      'country_slug' => ['nullable', 'string', 'max:80'],
      'phone' => ['nullable', 'string', 'max:40'],
    ]);

    if (empty($validated['country']) && empty($validated['country_id']) && empty($validated['country_slug'])) {
      $validated['country'] = 'nigeria';
    }

    $result = $commerce->checkout($enrollment, $validated, $request, $request->user());

    return $this->responder->success(
      data: [
        'order' => $commerce->orderPayload($result['order']),
        'checkout' => $result['checkout'],
        'donation_reference' => $result['donation']->reference,
      ],
      message: 'Checkout started. Complete payment to activate enrollment.',
      status: 201,
    );
  }

  public function myOrders(Request $request, CourseCommerceService $commerce): JsonResponse
  {
    $paginator = CourseOrder::query()
      ->where('user_id', $request->user()->id)
      ->with(['course:id,uuid,title,slug', 'invoices', 'donation'])
      ->latest()
      ->paginate(min(50, max(1, (int) $request->query('per_page', 25))));

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
      message: 'Orders retrieved.',
    );
  }
}
