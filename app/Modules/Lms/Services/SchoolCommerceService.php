<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Models\SchoolOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SchoolCommerceService implements ServiceContract
{
  public function __construct(
    private readonly DonationCheckoutService $donationCheckout,
    private readonly SchoolEnrollmentService $schoolEnrollments,
    private readonly LmsAuditService $audit,
    private readonly CommunicationLmsBridge $communicationLms,
    private readonly LearnerTypeResolver $learnerTypes,
    private readonly PricingEngine $pricingEngine,
  ) {}

  /** An unpaid order whose price no longer applies is cancelled, never repriced in place. */
  private function supersede(SchoolOrder $order): void
  {
    $order->loadMissing('donation');
    if ($order->donation !== null && in_array($order->donation->status, [DonationStatus::Pending, DonationStatus::Processing], true)) {
      $order->donation->fill(['status' => DonationStatus::Cancelled])->save();
    }
    $order->fill([
      'status' => CourseOrderStatus::Cancelled,
      'cancelled_at' => now(),
      'metadata' => array_merge($order->metadata ?? [], ['cancel_reason' => 'superseded_by_repricing']),
    ])->save();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateOrders(array $filters = []): LengthAwarePaginator
  {
    $query = $this->filteredOrders($filters)
      ->with(['school:id,uuid,title,slug', 'user:id,uuid,name,email', 'schoolEnrollment', 'donation'])
      ->latest();

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * School enrollment revenue (paid orders) for the same filters, by pricing category and currency.
   *
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function revenueSummary(array $filters = []): array
  {
    $rows = $this->filteredOrders(array_merge($filters, ['status' => CourseOrderStatus::Paid->value]))
      ->selectRaw('pricing_category, currency, COUNT(*) as orders, SUM(amount) as revenue')
      ->groupBy('pricing_category', 'currency')
      ->get();

    return [
      'payment_purpose' => 'school_enrollment',
      'paid_orders' => (int) $rows->sum('orders'),
      'by_currency' => $rows->groupBy('currency')->map(fn ($group) => round((float) $group->sum('revenue'), 2))->all(),
      'by_pricing_category' => $rows->map(fn ($row) => [
        'pricing_category' => $row->pricing_category ?? 'unclassified',
        'currency' => $row->currency,
        'orders' => (int) $row->orders,
        'revenue' => round((float) $row->revenue, 2),
      ])->values()->all(),
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return \Illuminate\Database\Eloquent\Builder<SchoolOrder>
   */
  private function filteredOrders(array $filters)
  {
    $query = SchoolOrder::query();

    if (! empty($filters['pricing_category'])) {
      $query->where('pricing_category', $filters['pricing_category']);
    }
    if (! empty($filters['payment_method'])) {
      $query->where('payment_method', $filters['payment_method']);
    }
    if (! empty($filters['date_from'])) {
      $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse((string) $filters['date_from'])->startOfDay());
    }
    if (! empty($filters['date_to'])) {
      $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse((string) $filters['date_to'])->endOfDay());
    }
    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['school_id'])) {
      $query->whereHas('school', fn ($q) => $q->where('uuid', $filters['school_id']));
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
          ->orWhereHas('donation', fn ($dq) => $dq->where('reference', 'like', "%{$search}%"));
      });
    }

    return $query;
  }

  public function confirmOffline(SchoolOrder $order, User $actor): SchoolOrder
  {
    $order->loadMissing(['donation']);
    if ($order->donation === null) {
      throw ValidationException::withMessages(['order' => ['Order has no linked donation payment.']]);
    }

    $this->donationCheckout->confirmSucceeded($order->donation, $actor);

    return $order->fresh(['school', 'schoolEnrollment', 'donation']);
  }

  public function rejectOffline(SchoolOrder $order, User $actor, ?string $reason = null): SchoolOrder
  {
    $order->loadMissing(['donation', 'school', 'user', 'schoolEnrollment']);

    if (! in_array($order->status, [CourseOrderStatus::AwaitingPayment, CourseOrderStatus::Pending], true)) {
      throw ValidationException::withMessages(['order' => ['Only pending offline orders can be rejected.']]);
    }

    return DB::transaction(function () use ($order, $actor, $reason): SchoolOrder {
      if ($order->donation !== null) {
        $order->donation->fill(['status' => \App\Modules\Donations\Enums\DonationStatus::Failed])->save();
      }

      $order->fill(['status' => CourseOrderStatus::Failed])->save();

      if ($order->schoolEnrollment !== null && $order->schoolEnrollment->status === EnrollmentStatus::PendingPayment) {
        $order->schoolEnrollment->forceFill(['status' => EnrollmentStatus::Cancelled])->save();
      }

      $this->audit->record(
        null,
        $actor,
        'school_commerce.offline_rejected',
        'School offline payment rejected.',
        null,
        null,
        ['order' => $order->uuid, 'school_id' => $order->school?->uuid, 'reason' => $reason],
      );

      if ($order->user !== null) {
        $this->communicationLms->notifyPaymentRejected(
          $order->user,
          (string) ($order->school?->title ?? 'School'),
          $order->donation?->reference ?? $order->order_number,
          $reason,
        );
      }

      return $order->fresh(['school', 'schoolEnrollment', 'donation', 'user']);
    });
  }

  /**
   * Re-resolve the learner's eligibility and the school price from current data. A pending enrollment
   * created as a visitor is repriced at the member rate once the membership is approved (and the
   * reverse when approval is withdrawn). Never reads a price from the request.
   *
   * @return array<string, mixed>
   */
  public function refreshPricing(SchoolEnrollment $enrollment): array
  {
    $enrollment->loadMissing(['school', 'user']);
    $owner = $enrollment->user;
    $member = $owner ? $this->learnerTypes->resolveMember($owner) : null;
    $learnerType = $owner ? $this->learnerTypes->resolve($owner) : LearnerType::Public;

    $pricing = $this->pricingEngine->resolveSchool($enrollment->school, $learnerType);
    $approval = $member?->approval_status instanceof \BackedEnum ? $member->approval_status->value : ($member?->approval_status ?? null);
    $status = $member?->status instanceof \BackedEnum ? $member->status->value : ($member?->status ?? null);
    $pricing['membership_status'] = $member === null ? 'visitor' : ($learnerType === LearnerType::Member ? 'approved_member' : 'member_'.($status ?: $approval ?: 'unapproved'));
    $pricing['eligible_for_member_price'] = $learnerType === LearnerType::Member;
    $pricing['resolved_at'] = now()->toIso8601String();

    if ($enrollment->status === EnrollmentStatus::PendingPayment) {
      $currentType = $enrollment->learner_type instanceof \BackedEnum ? $enrollment->learner_type->value : (string) $enrollment->learner_type;
      if (abs((float) $enrollment->price_paid - (float) $pricing['amount']) >= 0.005
        || $currentType !== $learnerType->value
        || (string) $enrollment->currency !== (string) $pricing['currency']) {
        $enrollment->forceFill([
          'learner_type' => $learnerType,
          'member_id' => $member?->id,
          'price_paid' => $pricing['amount'],
          'currency' => $pricing['currency'],
          'metadata' => array_merge($enrollment->metadata ?? [], ['pricing' => $pricing]),
        ])->save();
      }
    }

    return $pricing;
  }

  /**
   * @return array{order: SchoolOrder, checkout: array<string, mixed>, donation: Donation}
   */
  public function checkout(SchoolEnrollment $enrollment, array $payload, Request $request, User $user): array
  {
    $enrollment->loadMissing(['school', 'user']);

    if ($enrollment->user_id !== $user->id && ! $user->hasAnyPermission(['course_payments.manage', 'courses.manage', 'courses.enroll'])) {
      abort(403);
    }

    if ($enrollment->status !== EnrollmentStatus::PendingPayment) {
      throw ValidationException::withMessages([
        'enrollment' => ['School enrollment is not awaiting payment.'],
      ]);
    }

    $method = (string) ($payload['payment_method'] ?? 'paypal');
    if (! in_array($method, (array) config('donations.school_enrollment_methods', ['paypal']), true)) {
      throw ValidationException::withMessages([
        'payment_method' => ['School enrollment is paid through the global PayPal checkout.'],
      ]);
    }

    $pricing = $this->refreshPricing($enrollment);
    $amount = (float) $pricing['amount'];
    if ($amount <= 0) {
      throw ValidationException::withMessages([
        'enrollment' => ['No payment required for this school enrollment.'],
      ]);
    }
    $list = (float) ($pricing['standard_price'] ?? $amount);
    $discount = max(0, round($list - $amount, 2));
    $currency = (string) $pricing['currency'];

    $result = DB::transaction(function () use ($enrollment, $payload, $request, $user, $method, $amount, $list, $discount, $pricing, $currency): array {
      $order = SchoolOrder::query()
        ->where('school_enrollment_id', $enrollment->id)
        ->whereIn('status', [CourseOrderStatus::Pending->value, CourseOrderStatus::AwaitingPayment->value, CourseOrderStatus::Failed->value])
        ->latest()
        ->lockForUpdate()
        ->first();

      if ($order !== null && (abs((float) $order->amount - $amount) >= 0.005
        || (string) $order->currency !== $currency
        || ($order->pricing_category !== null && $order->pricing_category !== $pricing['pricing_category']))) {
        $this->supersede($order);
        $order = null;
      }

      if ($order === null) {
        $order = SchoolOrder::query()->create([
          'order_number' => 'ORD-SCH-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
          'school_enrollment_id' => $enrollment->id,
          'school_id' => $enrollment->school_id,
          'user_id' => $enrollment->user_id,
          'list_amount' => $list,
          'discount_amount' => $discount,
          'amount' => $amount,
          'currency' => $currency,
          'learner_type' => $pricing['audience'],
          'pricing_category' => $pricing['pricing_category'],
          'membership_status' => $pricing['membership_status'],
          'status' => CourseOrderStatus::Pending,
          'pricing_snapshot' => $pricing,
        ]);
      } else {
        $order->fill([
          'pricing_category' => $pricing['pricing_category'],
          'membership_status' => $pricing['membership_status'],
          'pricing_snapshot' => $pricing,
        ])->save();
      }

      $result = $this->donationCheckout->checkout([
        'global' => true,
        'country' => $payload['country'] ?? $payload['country_slug'] ?? null,
        'country_id' => $payload['country_id'] ?? null,
        'payment_method' => $method,
        'amount' => $order->amount,
        'currency' => $order->currency,
        'donor_name' => $user->name,
        'donor_email' => $user->email,
        'donor_phone' => $payload['phone'] ?? null,
        'frequency' => 'one_time',
        'notes' => 'School enrollment: '.($enrollment->school?->title ?? $order->order_number),
        'metadata' => [
          'purpose' => 'school_order',
          'source' => 'lms_school_checkout',
          'school_order_uuid' => $order->uuid,
          'school_enrollment_uuid' => $enrollment->uuid,
          'school_uuid' => $enrollment->school?->uuid,
          'school_title' => $enrollment->school?->title,
          'pricing_category' => $pricing['pricing_category'],
          'membership_status' => $pricing['membership_status'],
          'description' => 'School enrollment: '.($enrollment->school?->title ?? $order->order_number),
          'return_path' => $enrollment->school?->slug ? '/schools/'.$enrollment->school->slug : null,
        ],
      ], $request, $user);

      /** @var Donation $donation */
      $donation = $result['donation'];
      $checkout = $result['checkout'];

      $order->fill([
        'donation_id' => $donation->id,
        'payment_method' => $method,
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
        'order' => $order->fresh(['school', 'donation']),
        'checkout' => $checkout,
        'donation' => $donation,
      ];
    });

    if (in_array($method, ['offline', 'bank_account', 'wire'], true)) {
      $this->communicationLms->notifyOfflinePaymentSubmitted(
        $user,
        (string) ($enrollment->school?->title ?? 'School programme'),
        $result['donation']->reference,
      );
    }

    return $result;
  }

  public function activateFromDonation(Donation $donation, ?User $actor = null): ?SchoolOrder
  {
    $meta = $donation->metadata ?? [];
    if (($meta['purpose'] ?? null) !== 'school_order') {
      return null;
    }

    $order = null;
    if (! empty($meta['school_order_uuid'])) {
      $order = SchoolOrder::query()->where('uuid', $meta['school_order_uuid'])->first();
    }
    if ($order === null) {
      $order = SchoolOrder::query()->where('donation_id', $donation->id)->first();
    }
    if ($order === null) {
      return null;
    }

    if ($donation->status !== DonationStatus::Succeeded) {
      return null;
    }

    if ($order->status === CourseOrderStatus::Paid) {
      if ((int) $order->donation_id !== (int) $donation->id) {
        // A second verified payment for an already-paid order: never grants anything twice; flagged for refund review.
        $this->audit->record(null, $actor, 'school_commerce.duplicate_payment', 'Additional payment received for a paid school order.', null, null, [
          'order' => $order->uuid,
          'donation' => $donation->uuid,
        ]);
      }

      return $order;
    }

    if (in_array($order->status, [CourseOrderStatus::Cancelled, CourseOrderStatus::Refunded], true)) {
      // A payment that arrives for a superseded or cancelled order never grants access; flagged for refund review.
      $this->audit->record(null, $actor, 'school_commerce.payment_for_closed_order', 'Payment received for a cancelled school order; enrollment not activated.', null, null, [
        'order' => $order->uuid,
        'donation' => $donation->uuid,
        'order_status' => $order->status->value,
      ]);

      return null;
    }

    if (abs((float) $order->amount - (float) $donation->amount) >= 0.005
      || strtoupper((string) $order->currency) !== strtoupper((string) $donation->currency)) {
      $this->audit->record(null, $actor, 'school_commerce.amount_mismatch', 'Payment amount does not match the school order; enrollment not activated.', null, null, [
        'order' => $order->uuid,
        'donation' => $donation->uuid,
        'order_amount' => (float) $order->amount,
        'payment_amount' => (float) $donation->amount,
      ]);

      return null;
    }

    $captureId = $donation->payments()->latest()->first()?->raw_payload['capture_id'] ?? null;

    return DB::transaction(function () use ($order, $donation, $actor, $captureId): SchoolOrder {
      $claimed = SchoolOrder::query()
        ->whereKey($order->id)
        ->where('status', '!=', CourseOrderStatus::Paid->value)
        ->update([
          'status' => CourseOrderStatus::Paid->value,
          'paid_at' => $donation->paid_at ?? now(),
          'donation_id' => $donation->id,
          'provider_intent_id' => $donation->provider_intent_id,
          'provider_transaction_id' => is_string($captureId) ? $captureId : null,
          'payment_method' => $donation->payment_method instanceof \BackedEnum ? $donation->payment_method->value : $donation->payment_method,
          'updated_at' => now(),
        ]);
      if ($claimed === 0) {
        return $order->fresh(['school', 'schoolEnrollment', 'donation']) ?? $order;
      }
      $order->refresh();

      $enrollment = $order->schoolEnrollment;
      if ($enrollment && $enrollment->status === EnrollmentStatus::PendingPayment) {
        $this->schoolEnrollments->activate($enrollment);
        $enrollment->forceFill([
          'payment_reference' => $donation->reference,
        ])->save();
      }

      if ($enrollment?->school) {
        $this->audit->record(null, $actor, 'school.commerce.paid', 'School order paid for '.$enrollment->school->title, null, null, [
          'order' => $order->uuid,
          'donation' => $donation->uuid,
          'school_uuid' => $enrollment->school->uuid,
        ]);
      }

      $paidOrder = $order->fresh(['school', 'schoolEnrollment', 'donation']);
      $this->communicationLms->notifySchoolPaymentConfirmed($paidOrder, $donation);

      return $paidOrder;
    });
  }

  /** @return array<string, mixed> */
  public function orderPayload(SchoolOrder $order): array
  {
    $order->loadMissing(['school:id,uuid,title,slug', 'user:id,uuid,name,email', 'schoolEnrollment', 'donation']);

    return [
      'id' => $order->uuid,
      'order_number' => $order->order_number,
      'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status,
      'amount' => (float) $order->amount,
      'list_amount' => (float) $order->list_amount,
      'discount_amount' => (float) $order->discount_amount,
      'currency' => $order->currency,
      'payment_purpose' => 'school_enrollment',
      'payment_method' => $order->payment_method,
      'payment_provider' => $order->payment_method,
      'learner_type' => $order->learner_type,
      'pricing_category' => $order->pricing_category,
      'membership_status' => $order->membership_status,
      'provider_order_id' => $order->provider_intent_id,
      'provider_transaction_id' => $order->provider_transaction_id,
      'created_at' => $order->created_at?->toIso8601String(),
      'paid_at' => $order->paid_at?->toIso8601String(),
      'completed_at' => $order->paid_at?->toIso8601String(),
      'cancelled_at' => $order->cancelled_at?->toIso8601String(),
      'school' => $order->school ? [
        'id' => $order->school->uuid,
        'title' => $order->school->title,
        'slug' => $order->school->slug,
      ] : null,
      'learner' => $order->user ? [
        'id' => $order->user->uuid,
        'name' => $order->user->name,
        'email' => $order->user->email,
      ] : null,
      'school_enrollment_id' => $order->schoolEnrollment?->uuid,
      'enrollment_status' => $order->schoolEnrollment?->status instanceof \BackedEnum
        ? $order->schoolEnrollment->status->value
        : $order->schoolEnrollment?->status,
      'donation_reference' => $order->donation?->reference,
      'transaction_reference' => $order->donation?->reference ?? $order->provider_intent_id,
    ];
  }
}
