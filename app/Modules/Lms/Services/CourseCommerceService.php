<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use App\Modules\Donations\Services\PaymentGatewayManager;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\CourseRefundStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\CourseRefund;
use App\Modules\Lms\Models\Enrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Course commerce orchestrator — reuses Donation PaymentGatewayManager / checkout.
 * Does not duplicate Paystack/Flutterwave/Stripe/offline gateway logic.
 */
final class CourseCommerceService implements ServiceContract
{
  public function __construct(
    private readonly DonationCheckoutService $donationCheckout,
    private readonly PaymentGatewayManager $gateways,
    private readonly CourseInvoiceService $invoices,
    private readonly LmsAuditService $audit,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateOrders(array $filters = []): LengthAwarePaginator
  {
    $query = CourseOrder::query()
      ->with(['course:id,uuid,title,slug', 'user:id,uuid,name,email', 'enrollment', 'invoice', 'donation'])
      ->latest();

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['course_id'])) {
      $query->whereHas('course', fn ($q) => $q->where('uuid', $filters['course_id']));
    }
    if (! empty($filters['user_id'])) {
      $userId = User::query()->where('uuid', $filters['user_id'])->value('id');
      if ($userId) {
        $query->where('user_id', $userId);
      }
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('order_number', 'like', "%{$search}%")
          ->orWhere('coupon_code', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * Start payment for a pending_payment enrollment via Donation payment engine.
   *
   * @return array{order: CourseOrder, checkout: array<string, mixed>, donation: Donation}
   */
  public function checkout(Enrollment $enrollment, array $payload, Request $request, User $user): array
  {
    $enrollment->loadMissing(['course', 'user']);

    if ($enrollment->user_id !== $user->id && ! $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'courses.enroll'])) {
      abort(403);
    }

    if ($enrollment->status !== EnrollmentStatus::PendingPayment) {
      throw ValidationException::withMessages([
        'enrollment' => ['Enrollment is not awaiting payment.'],
      ]);
    }

    $amount = (float) ($enrollment->price_paid ?? 0);
    if ($amount <= 0) {
      throw ValidationException::withMessages([
        'enrollment' => ['No payment required for this enrollment.'],
      ]);
    }

    $method = PaymentMethod::from((string) $payload['payment_method']);
    $pricing = $enrollment->metadata['pricing'] ?? [];
    $list = (float) ($pricing['list_price'] ?? $amount);
    $discount = max(0, round($list - $amount, 2));

    return DB::transaction(function () use ($enrollment, $payload, $request, $user, $method, $amount, $list, $discount, $pricing): array {
      $order = CourseOrder::query()
        ->where('enrollment_id', $enrollment->id)
        ->whereIn('status', [CourseOrderStatus::Pending->value, CourseOrderStatus::AwaitingPayment->value, CourseOrderStatus::Failed->value])
        ->latest()
        ->first();

      if ($order === null) {
        $order = CourseOrder::query()->create([
          'order_number' => 'ORD-LMS-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
          'enrollment_id' => $enrollment->id,
          'course_id' => $enrollment->course_id,
          'user_id' => $enrollment->user_id,
          'list_amount' => $list,
          'discount_amount' => $discount,
          'amount' => $amount,
          'currency' => $enrollment->currency ?: 'USD',
          'coupon_code' => $enrollment->coupon_code,
          'learner_type' => $enrollment->learner_type instanceof \BackedEnum
            ? $enrollment->learner_type->value
            : $enrollment->learner_type,
          'status' => CourseOrderStatus::Pending,
          'pricing_snapshot' => $pricing,
        ]);
      }

      $this->invoices->issueInvoice($order, $user);

      $result = $this->donationCheckout->checkout([
        'country' => $payload['country'] ?? $payload['country_slug'] ?? null,
        'country_id' => $payload['country_id'] ?? null,
        'payment_method' => $method->value,
        'amount' => $order->amount,
        'currency' => $order->currency,
        'donor_name' => $user->name,
        'donor_email' => $user->email,
        'donor_phone' => $payload['phone'] ?? null,
        'frequency' => 'one_time',
        'notes' => 'Course enrollment: '.($enrollment->course?->title ?? $order->order_number),
        'metadata' => [
          'purpose' => 'course_order',
          'source' => 'lms_checkout',
          'course_order_uuid' => $order->uuid,
          'enrollment_uuid' => $enrollment->uuid,
          'course_uuid' => $enrollment->course?->uuid,
        ],
      ], $request, $user);

      /** @var Donation $donation */
      $donation = $result['donation'];
      $checkout = $result['checkout'];

      $order->fill([
        'donation_id' => $donation->id,
        'payment_method' => $method->value,
        'provider_intent_id' => $donation->provider_intent_id,
        'status' => CourseOrderStatus::AwaitingPayment,
        'metadata' => array_merge($order->metadata ?? [], [
          'checkout_type' => $checkout['type'] ?? null,
        ]),
      ])->save();

      $enrollment->forceFill([
        'payment_reference' => $donation->reference,
      ])->save();

      return [
        'order' => $order->fresh(['course', 'invoice', 'donation']),
        'checkout' => $checkout,
        'donation' => $donation,
      ];
    });
  }

  public function activateFromDonation(Donation $donation, ?User $actor = null): ?CourseOrder
  {
    $meta = $donation->metadata ?? [];
    if (($meta['purpose'] ?? null) !== 'course_order') {
      return null;
    }

    $order = null;
    if (! empty($meta['course_order_uuid'])) {
      $order = CourseOrder::query()->where('uuid', $meta['course_order_uuid'])->first();
    }
    if ($order === null) {
      $order = CourseOrder::query()->where('donation_id', $donation->id)->first();
    }
    if ($order === null) {
      return null;
    }

    if ($order->status === CourseOrderStatus::Paid) {
      return $order;
    }

    return DB::transaction(function () use ($order, $donation, $actor): CourseOrder {
      $order->fill([
        'status' => CourseOrderStatus::Paid,
        'paid_at' => $donation->paid_at ?? now(),
        'donation_id' => $donation->id,
        'provider_intent_id' => $donation->provider_intent_id,
      ])->save();

      $enrollment = $order->enrollment;
      if ($enrollment && $enrollment->status === EnrollmentStatus::PendingPayment) {
        $enrollment->forceFill([
          'status' => EnrollmentStatus::Active,
          'payment_reference' => $donation->reference,
        ])->save();

        $enrollment->course?->increment('enrollment_count');

        if ($enrollment->coupon_code) {
          // Redeem once on paid confirm (free enrollments redeem at enroll time).
          $already = (int) (($enrollment->metadata['coupon_redeemed'] ?? false) ? 1 : 0);
          if ($already === 0) {
            CourseCoupon::query()->where('code', $enrollment->coupon_code)->increment('redeemed_count');
            $enrollment->forceFill([
              'metadata' => array_merge($enrollment->metadata ?? [], ['coupon_redeemed' => true]),
            ])->save();
          }
        }
      }

      $this->invoices->issueReceipt($order->fresh(['course', 'user']), $actor);

      if ($enrollment?->course) {
        $this->audit->record($enrollment->course, $actor, 'commerce.paid', 'Course order paid.', null, null, [
          'order' => $order->uuid,
          'donation' => $donation->uuid,
        ]);
      }

      return $order->fresh(['course', 'enrollment', 'invoice', 'invoices', 'donation']);
    });
  }

  public function confirmOffline(CourseOrder $order, User $actor): CourseOrder
  {
    $order->loadMissing(['donation']);
    if ($order->donation === null) {
      throw ValidationException::withMessages(['order' => ['Order has no linked donation payment.']]);
    }

    $this->donationCheckout->confirmSucceeded($order->donation, $actor);

    return $order->fresh(['course', 'enrollment', 'invoice', 'invoices', 'donation']);
  }

  /**
   * @return array{refund: CourseRefund, order: CourseOrder}
   */
  public function refund(CourseOrder $order, User $actor, ?float $amount = null, ?string $reason = null): array
  {
    $order->loadMissing(['donation.payments', 'enrollment']);

    if (! in_array($order->status, [CourseOrderStatus::Paid, CourseOrderStatus::PartiallyRefunded], true)) {
      throw ValidationException::withMessages(['order' => ['Only paid orders can be refunded.']]);
    }

    $refundAmount = $amount ?? (float) $order->amount;
    if ($refundAmount <= 0 || $refundAmount > (float) $order->amount) {
      throw ValidationException::withMessages(['amount' => ['Invalid refund amount.']]);
    }

    return DB::transaction(function () use ($order, $actor, $refundAmount, $reason): array {
      $refund = CourseRefund::query()->create([
        'order_id' => $order->id,
        'donation_id' => $order->donation_id,
        'amount' => $refundAmount,
        'currency' => $order->currency,
        'status' => CourseRefundStatus::Pending,
        'reason' => $reason,
        'requested_by_user_id' => $actor->id,
      ]);

      $gatewayRefunded = false;
      $payment = $order->donation?->payments()->latest()->first();
      if ($payment !== null && $order->payment_method) {
        try {
          $gatewayRefunded = $this->gateways->for($order->payment_method)->refund($payment, $refundAmount);
        } catch (\Throwable) {
          $gatewayRefunded = false;
        }
      }

      $refund->fill([
        'gateway_refunded' => $gatewayRefunded,
        'status' => CourseRefundStatus::Processed,
        'processed_by_user_id' => $actor->id,
        'processed_at' => now(),
      ])->save();

      $full = abs($refundAmount - (float) $order->amount) < 0.01;
      $order->fill([
        'status' => $full ? CourseOrderStatus::Refunded : CourseOrderStatus::PartiallyRefunded,
      ])->save();

      if ($order->donation) {
        $order->donation->fill([
          'status' => $full ? DonationStatus::Refunded : $order->donation->status,
        ])->save();
      }

      if ($full && $order->enrollment && $order->enrollment->status === EnrollmentStatus::Active) {
        $order->enrollment->forceFill(['status' => EnrollmentStatus::Cancelled])->save();
      }

      return [
        'refund' => $refund->fresh(),
        'order' => $order->fresh(['course', 'enrollment', 'refunds', 'donation']),
      ];
    });
  }

  public function orderPayload(CourseOrder $order): array
  {
    $order->loadMissing(['course:id,uuid,title,slug', 'user:id,uuid,name,email', 'enrollment', 'invoices', 'donation', 'refunds']);

    return [
      'id' => $order->uuid,
      'order_number' => $order->order_number,
      'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status,
      'amount' => (float) $order->amount,
      'list_amount' => (float) $order->list_amount,
      'discount_amount' => (float) $order->discount_amount,
      'currency' => $order->currency,
      'coupon_code' => $order->coupon_code,
      'payment_method' => $order->payment_method,
      'paid_at' => $order->paid_at?->toIso8601String(),
      'course' => $order->course ? [
        'id' => $order->course->uuid,
        'title' => $order->course->title,
        'slug' => $order->course->slug,
      ] : null,
      'learner' => $order->user ? [
        'id' => $order->user->uuid,
        'name' => $order->user->name,
        'email' => $order->user->email,
      ] : null,
      'enrollment_id' => $order->enrollment?->uuid,
      'donation_reference' => $order->donation?->reference,
      'invoices' => $order->invoices->map(fn ($inv) => [
        'id' => $inv->uuid,
        'number' => $inv->invoice_number,
        'type' => $inv->type,
        'url' => $inv->url(),
        'issued_at' => $inv->issued_at?->toIso8601String(),
      ])->values()->all(),
      'refunds' => $order->refunds->map(fn ($r) => [
        'id' => $r->uuid,
        'amount' => (float) $r->amount,
        'status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
        'reason' => $r->reason,
        'gateway_refunded' => $r->gateway_refunded,
        'processed_at' => $r->processed_at?->toIso8601String(),
      ])->values()->all(),
    ];
  }
}
