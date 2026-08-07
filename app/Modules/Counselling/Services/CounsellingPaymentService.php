<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Counselling\Enums\ClientType;
use App\Modules\Counselling\Enums\PaymentStatus;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingPayment;
use App\Modules\Counselling\Models\CounsellingService;
use Illuminate\Support\Facades\DB;

final class CounsellingPaymentService implements ServiceContract
{
  public function __construct(
    private readonly CounsellingAuditService $auditService,
    private readonly CounsellingNotificationService $notificationService,
  ) {}

  public function createPendingForCase(CounsellingCase $case): CounsellingPayment
  {
    $case->loadMissing('service');
    $service = $case->service;

    if ($service === null) {
      throw new \InvalidArgumentException('Case must have a service to create payment.');
    }

    $clientType = $case->client_type instanceof ClientType
      ? $case->client_type
      : ClientType::tryFrom((string) $case->client_type) ?? ClientType::Visitor;

    $resolved = $this->resolveAmount($service, $clientType);

    return CounsellingPayment::query()->create([
      'case_id' => $case->id,
      'service_id' => $service->id,
      'status' => PaymentStatus::Pending,
      'amount' => $resolved['amount'],
      'currency' => $resolved['currency'],
      'client_type' => $clientType,
      'metadata' => ['pricing' => $resolved],
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createManualInvoice(CounsellingCase $case, array $data, ?User $actor = null): CounsellingPayment
  {
    $case->loadMissing('service');
    $service = $case->service;
    if ($service === null) {
      throw new \InvalidArgumentException('Case must have a service to create payment.');
    }

    $clientType = $case->client_type instanceof ClientType
      ? $case->client_type
      : ClientType::tryFrom((string) $case->client_type) ?? ClientType::Visitor;

    $payment = CounsellingPayment::query()->create([
      'case_id' => $case->id,
      'service_id' => $service->id,
      'status' => PaymentStatus::Pending,
      'amount' => (float) $data['amount'],
      'currency' => (string) ($data['currency'] ?? $service->currency ?? 'USD'),
      'client_type' => $clientType,
      'metadata' => [
        'payment_type' => $data['payment_type'] ?? 'paid',
        'note' => $data['note'] ?? null,
        'created_by_admin' => true,
      ],
    ]);

    $this->auditService->record(
      $case,
      $actor,
      'payment.invoice_created',
      'Payment invoice generated',
      'Invoice of '.$payment->amount.' '.$payment->currency.' created.',
      ['payment_id' => $payment->uuid],
    );

    $this->notificationService->notifyPaymentRequired($case->fresh(['service']), $payment);

    return $payment;
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function markPaid(CounsellingPayment $payment, array $data = [], ?User $actor = null): CounsellingPayment
  {
    return DB::transaction(function () use ($payment, $data, $actor): CounsellingPayment {
      $payment->status = PaymentStatus::Paid;
      $payment->paid_at = now();
      $payment->payment_reference = $data['payment_reference'] ?? $payment->payment_reference;
      $payment->provider = $data['provider'] ?? $payment->provider;
      $payment->metadata = array_merge($payment->metadata ?? [], ['paid' => $data]);
      $payment->save();

      $case = $payment->case;
      if ($case !== null) {
        if (in_array($case->status, [
          \App\Modules\Counselling\Enums\CaseStatus::WaitingPayment,
        ], true)) {
          $case->status = \App\Modules\Counselling\Enums\CaseStatus::PaymentConfirmed;
          $case->save();
        }

        $this->auditService->record(
          $case,
          $actor,
          'payment.paid',
          'Payment Received',
          'Payment of '.$payment->amount.' '.$payment->currency.' received.',
          ['payment_id' => $payment->uuid],
        );

        $this->notificationService->notifyPaymentReceived(
          $case->fresh(['service']),
          $payment,
        );
      }

      return $payment->fresh(['case.service', 'service']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function markFailed(CounsellingPayment $payment, array $data = [], ?User $actor = null): CounsellingPayment
  {
    return DB::transaction(function () use ($payment, $data, $actor): CounsellingPayment {
      $payment->status = PaymentStatus::Failed;
      $payment->metadata = array_merge($payment->metadata ?? [], ['failed' => $data]);
      $payment->save();

      $case = $payment->case;
      if ($case !== null) {
        $this->auditService->record(
          $case,
          $actor,
          'payment.failed',
          'Payment failed',
          'Payment attempt failed.',
          ['payment_id' => $payment->uuid, 'reason' => $data['reason'] ?? null],
        );
      }

      return $payment->fresh(['case.service', 'service']);
    });
  }

  /**
   * @return array{amount: float, currency: string, is_free: bool, list_price: float|null, client_type: string}
   */
  public function resolveAmount(CounsellingService $service, ClientType $clientType): array
  {
    $currency = $service->currency ?: 'USD';

    if ($service->is_free) {
      return [
        'amount' => 0.0,
        'currency' => $currency,
        'is_free' => true,
        'list_price' => 0.0,
        'client_type' => $clientType->value,
      ];
    }

    $listPrice = $clientType === ClientType::Member
      ? ($service->member_price !== null ? (float) $service->member_price : (float) ($service->visitor_price ?? 0))
      : (float) ($service->visitor_price ?? $service->member_price ?? 0);

    $amount = max(0, round($listPrice, 2));

    return [
      'amount' => $amount,
      'currency' => $currency,
      'is_free' => $amount <= 0,
      'list_price' => $listPrice,
      'client_type' => $clientType->value,
    ];
  }
}
