<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\PaymentActionRequest;
use App\Modules\Events\Http\Resources\EventRegistrationPaymentResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventPaymentService;
use Illuminate\Http\JsonResponse;

final class RegistrationPaymentAdminController extends ApiController
{
  public function offline(PaymentActionRequest $request, EventRegistration $registration, EventPaymentService $service): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $payment = $service->markPaidOffline($registration, $request->user(), $request->validated('notes'));

    return $this->responder->success(
      data: ['payment' => new EventRegistrationPaymentResource($payment)],
      message: 'Payment marked paid (offline).',
    );
  }

  public function approve(PaymentActionRequest $request, EventRegistration $registration, EventPaymentService $service): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $payment = $service->approveManual($registration, $request->user(), $request->validated('notes'));

    return $this->responder->success(
      data: ['payment' => new EventRegistrationPaymentResource($payment)],
      message: 'Payment manually approved.',
    );
  }

  public function waive(PaymentActionRequest $request, EventRegistration $registration, EventPaymentService $service): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $payment = $service->waive($registration, $request->user(), $request->validated('notes'));

    return $this->responder->success(
      data: ['payment' => new EventRegistrationPaymentResource($payment)],
      message: 'Payment waived.',
    );
  }

  public function coupon(PaymentActionRequest $request, EventRegistration $registration, EventPaymentService $service): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $code = (string) $request->validated('coupon_code');
    if ($code === '') {
      abort(422, 'coupon_code is required.');
    }

    $payment = $service->applyCoupon($registration, $code, $request->user());

    return $this->responder->success(
      data: ['payment' => new EventRegistrationPaymentResource($payment)],
      message: 'Coupon applied.',
    );
  }
}
