<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
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
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateOrders(array $filters = []): LengthAwarePaginator
  {
    $query = SchoolOrder::query()
      ->with(['school:id,uuid,title,slug', 'user:id,uuid,name,email', 'schoolEnrollment', 'donation'])
      ->latest();

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

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
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

    $amount = (float) ($enrollment->price_paid ?? 0);
    if ($amount <= 0) {
      throw ValidationException::withMessages([
        'enrollment' => ['No payment required for this school enrollment.'],
      ]);
    }

    $method = (string) $payload['payment_method'];
    $pricing = $enrollment->metadata['pricing'] ?? [];
    $list = (float) ($pricing['list_price'] ?? $amount);
    $discount = max(0, round($list - $amount, 2));

    $result = DB::transaction(function () use ($enrollment, $payload, $request, $user, $method, $amount, $list, $discount, $pricing): array {
      $order = SchoolOrder::query()
        ->where('school_enrollment_id', $enrollment->id)
        ->whereIn('status', [CourseOrderStatus::Pending->value, CourseOrderStatus::AwaitingPayment->value, CourseOrderStatus::Failed->value])
        ->latest()
        ->first();

      if ($order === null) {
        $order = SchoolOrder::query()->create([
          'order_number' => 'ORD-SCH-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
          'school_enrollment_id' => $enrollment->id,
          'school_id' => $enrollment->school_id,
          'user_id' => $enrollment->user_id,
          'list_amount' => $list,
          'discount_amount' => $discount,
          'amount' => $amount,
          'currency' => $enrollment->currency ?: 'USD',
          'learner_type' => $enrollment->learner_type instanceof \BackedEnum
            ? $enrollment->learner_type->value
            : $enrollment->learner_type,
          'status' => CourseOrderStatus::Pending,
          'pricing_snapshot' => $pricing,
        ]);
      }

      $result = $this->donationCheckout->checkout([
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

    if ($order->status === CourseOrderStatus::Paid) {
      return $order;
    }

    return DB::transaction(function () use ($order, $donation, $actor): SchoolOrder {
      $order->fill([
        'status' => CourseOrderStatus::Paid,
        'paid_at' => $donation->paid_at ?? now(),
        'donation_id' => $donation->id,
        'provider_intent_id' => $donation->provider_intent_id,
      ])->save();

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
      'payment_method' => $order->payment_method,
      'paid_at' => $order->paid_at?->toIso8601String(),
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
