<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreCouponRequest;
use App\Modules\Events\Http\Resources\EventCouponResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCoupon;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CouponAdminController extends ApiController
{
  public function index(Request $request, Event $event): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $coupons = $event->coupons()->orderByDesc('created_at')->paginate(50);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($coupons, EventCouponResource::class),
      message: 'Coupons retrieved.',
    );
  }

  public function store(StoreCouponRequest $request, Event $event): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $coupon = EventCoupon::query()->create([
      ...$request->validated(),
      'event_id' => $event->id,
    ]);

    return $this->responder->success(
      data: ['coupon' => new EventCouponResource($coupon)],
      message: 'Coupon created.',
      status: 201,
    );
  }

  public function update(StoreCouponRequest $request, EventCoupon $coupon): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $coupon->fill($request->validated());
    $coupon->save();

    return $this->responder->success(
      data: ['coupon' => new EventCouponResource($coupon->fresh())],
      message: 'Coupon updated.',
    );
  }

  public function destroy(EventCoupon $coupon): JsonResponse
  {
    $this->authorize('permission', 'event_payments.manage');

    $coupon->delete();

    return $this->responder->success(data: null, message: 'Coupon deleted.');
  }
}
