<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\CouponAppliesTo;
use App\Modules\Lms\Enums\CouponDiscountType;
use App\Modules\Lms\Enums\CourseAudience;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\LmsSchool;

final class PricingEngine implements ServiceContract
{
  /**
   * @return array{amount: float|null, currency: string, is_free: bool, list_price: float|null, promotional: bool, coupon_applied: bool, coupon_code: string|null, audience: string}
   */
  public function resolve(Course $course, LearnerType $learnerType, ?string $couponCode = null): array
  {
    $audience = $course->audience instanceof CourseAudience
      ? $course->audience
      : CourseAudience::tryFrom((string) ($course->audience ?? 'both')) ?? CourseAudience::Both;

    $currency = $course->currency ?: 'USD';

    // Per-audience free flags (independent of the legacy global is_free).
    if ($learnerType === LearnerType::Member && (bool) ($course->member_free || $course->is_free)) {
      return $this->freeResult($currency, $audience);
    }
    if ($learnerType === LearnerType::Public && (bool) ($course->visitor_free || $course->is_free)) {
      return $this->freeResult($currency, $audience);
    }

    $list = $learnerType === LearnerType::Member
      ? ($course->member_price !== null ? (float) $course->member_price : (float) ($course->public_price ?? 0))
      : (float) ($course->public_price ?? $course->member_price ?? 0);

    $amount = $list;
    $promotional = false;

    if ($course->isPromotionActive()) {
      $amount = (float) $course->promotional_price;
      $promotional = true;
    }

    $couponApplied = false;
    $appliedCode = null;

    if ($couponCode) {
      $coupon = CourseCoupon::query()->where('code', strtoupper(trim($couponCode)))->first();
      if ($coupon && $coupon->isCurrentlyValid() && $this->couponApplies($coupon, $course, $learnerType)) {
        $amount = $this->applyCoupon($amount, $coupon);
        $couponApplied = true;
        $appliedCode = $coupon->code;
      }
    }

    $amount = max(0, round($amount, 2));

    return [
      'amount' => $amount,
      'currency' => $currency,
      'is_free' => $amount <= 0,
      'list_price' => $list,
      'promotional' => $promotional,
      'coupon_applied' => $couponApplied,
      'coupon_code' => $appliedCode,
      'audience' => $audience->value,
    ];
  }

  /**
   * @return array{amount: float, currency: string, is_free: bool, list_price: float, promotional: bool, coupon_applied: bool, coupon_code: null, audience: string}
   */
  public function resolveSchool(LmsSchool $school, LearnerType $learnerType): array
  {
    $currency = $school->currency ?: 'USD';
    $list = $learnerType === LearnerType::Member
      ? (float) ($school->member_price ?? 0)
      : (float) ($school->public_price ?? $school->member_price ?? 0);
    $amount = max(0, round($list, 2));

    return [
      'amount' => $amount,
      'currency' => $currency,
      'is_free' => $amount <= 0,
      'list_price' => $list,
      'promotional' => false,
      'coupon_applied' => false,
      'coupon_code' => null,
      'audience' => $learnerType->value,
    ];
  }

  /**
   * @return array{amount: float, currency: string, is_free: bool, list_price: float, promotional: bool, coupon_applied: bool, coupon_code: null, audience: string}
   */
  private function freeResult(string $currency, CourseAudience $audience): array
  {
    return [
      'amount' => 0.0,
      'currency' => $currency,
      'is_free' => true,
      'list_price' => 0.0,
      'promotional' => false,
      'coupon_applied' => false,
      'coupon_code' => null,
      'audience' => $audience->value,
    ];
  }

  private function couponApplies(CourseCoupon $coupon, Course $course, LearnerType $learnerType): bool
  {
    if ($coupon->course_id !== null && $coupon->course_id !== $course->id) {
      return false;
    }

    return match ($coupon->applies_to) {
      CouponAppliesTo::All => true,
      CouponAppliesTo::Member => $learnerType === LearnerType::Member,
      CouponAppliesTo::Public => $learnerType === LearnerType::Public,
      default => false,
    };
  }

  private function applyCoupon(float $amount, CourseCoupon $coupon): float
  {
    if ($coupon->discount_type === CouponDiscountType::Percent) {
      return $amount * (1 - ((float) $coupon->discount_value / 100));
    }

    return $amount - (float) $coupon->discount_value;
  }
}
