<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\CouponDiscountType;
use App\Modules\Events\Enums\PaymentMethodType;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCoupon;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventPaymentService implements ServiceContract
{
  public function ensurePendingPayment(EventRegistration $registration): EventRegistrationPayment
  {
    $registration->loadMissing('event');
    $event = $registration->event ?? Event::query()->find($registration->event_id);
    if ($event === null) {
      throw new \RuntimeException('Cannot ensure payment record without an event.');
    }

    /** @var EventRegistrationPayment|null $existing */
    $existing = $registration->payments()
      ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Approved->value])
      ->latest('id')
      ->first();

    if ($existing !== null) {
      return $existing;
    }

    $isFree = ! $event->is_paid || (int) round(((float) $event->price) * 100) === 0;

    return EventRegistrationPayment::query()->create([
      'registration_id' => $registration->id,
      'event_id' => $event->id,
      'amount' => $isFree ? 0 : (float) ($event->price ?? 0),
      'currency' => $event->currency ?? 'USD',
      'status' => $isFree ? PaymentStatus::Waived : PaymentStatus::Pending,
      'payment_method' => $isFree ? PaymentMethodType::Free : PaymentMethodType::Offline,
      'paid_at' => $isFree ? now() : null,
    ]);
  }

  public function applyCoupon(EventRegistration $registration, string $code, ?User $actor = null): EventRegistrationPayment
  {
    return DB::transaction(function () use ($registration, $code, $actor): EventRegistrationPayment {
      $registration->loadMissing('event');
      $event = $registration->event;
      if ($event === null || ! $event->is_paid) {
        throw ValidationException::withMessages(['coupon' => ['This event does not accept coupons.']]);
      }

      /** @var EventCoupon|null $coupon */
      $coupon = EventCoupon::query()
        ->where('event_id', $event->id)
        ->where('code', $code)
        ->first();

      if ($coupon === null || ! $coupon->isRedeemable()) {
        throw ValidationException::withMessages(['coupon' => ['Coupon is invalid or has expired.']]);
      }

      $payment = $this->ensurePendingPayment($registration);
      $basePrice = (float) ($event->price ?? 0);
      $discounted = $this->applyDiscount($basePrice, $coupon);
      $isZero = (int) round($discounted * 100) === 0;

      $payment->amount = $discounted;
      $payment->coupon_id = $coupon->id;
      $payment->payment_method = PaymentMethodType::Coupon;
      $payment->approved_by_user_id = $actor?->id;
      $payment->notes = sprintf('Coupon %s applied.', $coupon->code);
      if ($isZero) {
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
      }
      $payment->save();

      $coupon->increment('used_count');

      return $payment->fresh();
    });
  }

  public function markPaidOffline(EventRegistration $registration, User $actor, ?string $notes = null): EventRegistrationPayment
  {
    $payment = $this->ensurePendingPayment($registration);
    $payment->status = PaymentStatus::Paid;
    $payment->payment_method = PaymentMethodType::Offline;
    $payment->approved_by_user_id = $actor->id;
    $payment->paid_at = now();
    if ($notes !== null) {
      $payment->notes = $notes;
    }
    $payment->save();

    return $payment->fresh();
  }

  public function approveManual(EventRegistration $registration, User $actor, ?string $notes = null): EventRegistrationPayment
  {
    $payment = $this->ensurePendingPayment($registration);
    $payment->status = PaymentStatus::Approved;
    $payment->payment_method = PaymentMethodType::Manual;
    $payment->approved_by_user_id = $actor->id;
    if ($notes !== null) {
      $payment->notes = $notes;
    }
    $payment->save();

    return $payment->fresh();
  }

  public function waive(EventRegistration $registration, User $actor, ?string $notes = null): EventRegistrationPayment
  {
    $payment = $this->ensurePendingPayment($registration);
    $payment->status = PaymentStatus::Waived;
    $payment->amount = 0;
    $payment->approved_by_user_id = $actor->id;
    $payment->paid_at = now();
    if ($notes !== null) {
      $payment->notes = $notes;
    }
    $payment->save();

    return $payment->fresh();
  }

  public function linkDonation(EventRegistrationPayment $payment, int $donationId): EventRegistrationPayment
  {
    $payment->donation_id = $donationId;
    $payment->save();

    return $payment->fresh();
  }

  private function applyDiscount(float $basePrice, EventCoupon $coupon): float
  {
    $discountValue = (float) $coupon->discount_value;

    if ($coupon->discount_type === CouponDiscountType::Percent) {
      $reduction = $basePrice * ($discountValue / 100);

      return max(0, round($basePrice - $reduction, 2));
    }

    return max(0, round($basePrice - $discountValue, 2));
  }
}
