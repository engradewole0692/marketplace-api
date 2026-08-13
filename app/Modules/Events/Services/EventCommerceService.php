<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use App\Modules\Events\Enums\PaymentMethodType;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\TimelineEventType;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Event registration commerce — reuses Donation PaymentGatewayManager / checkout.
 */
final class EventCommerceService implements ServiceContract
{
  public function __construct(
    private readonly DonationCheckoutService $donationCheckout,
    private readonly EventPaymentService $eventPaymentService,
    private readonly RegistrationAuditService $auditService,
    private readonly RegistrationTimelineService $timelineService,
    private readonly CheckInTokenService $checkInTokenService,
    private readonly NotificationService $notificationService,
  ) {}

  /**
   * @return array{payment: EventRegistrationPayment, checkout: array<string, mixed>, donation: Donation}
   */
  public function checkout(EventRegistration $registration, array $payload, Request $request, ?User $user = null): array
  {
    $registration->loadMissing(['event', 'member']);

    $event = $registration->event;
    if ($event === null || ! $event->is_paid) {
      throw ValidationException::withMessages([
        'registration' => ['This event does not require payment.'],
      ]);
    }

    $payment = $this->eventPaymentService->ensurePendingPayment($registration);

    if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Waived], true)) {
      throw ValidationException::withMessages([
        'registration' => ['Payment has already been completed for this registration.'],
      ]);
    }

    $amount = (float) $payment->amount;
    if ($amount <= 0) {
      $actor = $this->resolveActor($registration, $user);
      $payment = $this->eventPaymentService->waive($registration, $actor, 'No payment required after discount.');
      $this->activateFromWaivedRegistration($registration->fresh(['event', 'member']), $actor);

      return [
        'payment' => $payment,
        'checkout' => ['type' => 'completed', 'message' => 'Registration confirmed — no payment required.'],
        'donation' => Donation::query()->make(),
      ];
    }

    $method = PaymentMethod::from((string) $payload['payment_method']);
    $donorName = $registration->contactName() ?? ($payload['donor_name'] ?? 'Event registrant');
    $donorEmail = $registration->contactEmail() ?? ($payload['donor_email'] ?? null);

    if ($donorEmail === null) {
      throw ValidationException::withMessages([
        'email' => ['A contact email is required to start checkout.'],
      ]);
    }

    return DB::transaction(function () use ($registration, $payload, $request, $user, $method, $payment, $amount, $event, $donorName, $donorEmail): array {
      $result = $this->donationCheckout->checkout([
        'country' => $payload['country'] ?? $payload['country_slug'] ?? null,
        'country_id' => $payload['country_id'] ?? null,
        'payment_method' => $method->value,
        'amount' => $amount,
        'currency' => $payment->currency ?? $event->currency ?? 'USD',
        'donor_name' => $donorName,
        'donor_email' => $donorEmail,
        'donor_phone' => $payload['phone'] ?? $registration->contactPhone(),
        'frequency' => 'one_time',
        'notes' => 'Event registration: '.($event->title ?? $registration->registration_number),
        'metadata' => [
          'purpose' => 'event_registration',
          'source' => 'events_checkout',
          'event_registration_payment_uuid' => $payment->uuid,
          'event_registration_uuid' => $registration->uuid,
          'event_uuid' => $event->uuid,
        ],
      ], $request, $user);

      /** @var Donation $donation */
      $donation = $result['donation'];
      $checkout = $result['checkout'];

      $payment->fill([
        'donation_id' => $donation->id,
        'payment_method' => $this->mapPaymentMethod($method),
      ])->save();

      return [
        'payment' => $payment->fresh(['registration', 'event']),
        'checkout' => $checkout,
        'donation' => $donation,
      ];
    });
  }

  public function activateFromDonation(Donation $donation, ?User $actor = null): ?EventRegistrationPayment
  {
    if (($donation->metadata['purpose'] ?? null) !== 'event_registration') {
      return null;
    }

    $payment = null;
    if (! empty($donation->metadata['event_registration_payment_uuid'])) {
      $payment = EventRegistrationPayment::query()
        ->where('uuid', $donation->metadata['event_registration_payment_uuid'])
        ->first();
    }

    if ($payment === null) {
      $payment = EventRegistrationPayment::query()->where('donation_id', $donation->id)->first();
    }

    if ($payment === null) {
      return null;
    }

    if ($payment->status === PaymentStatus::Paid) {
      return $payment;
    }

    return DB::transaction(function () use ($payment, $donation, $actor): EventRegistrationPayment {
      $payment->fill([
        'status' => PaymentStatus::Paid,
        'paid_at' => $donation->paid_at ?? now(),
        'donation_id' => $donation->id,
      ])->save();

      $registration = $payment->registration()->with('event')->first();
      if ($registration !== null && $registration->status === RegistrationStatus::Submitted) {
        $registration->fill([
          'status' => RegistrationStatus::Approved,
          'approved_at' => now(),
          'approved_by_user_id' => $actor?->id,
        ])->save();

        $this->auditService->record(
          RegistrationAuditEventType::StatusChanged,
          $registration,
          $actor,
          ['status' => RegistrationStatus::Submitted->value],
          ['status' => RegistrationStatus::Approved->value],
          ['reason' => 'Payment confirmed'],
        );
        $this->timelineService->record(
          $registration,
          TimelineEventType::StatusChanged,
          'Registration approved after payment confirmation.',
          $actor,
          ['donation_reference' => $donation->reference],
        );

        if ($registration->event?->check_in_enabled && ! $registration->checkInToken()->exists()) {
          $this->checkInTokenService->issue($registration, null, $actor);
        }

        try {
          $this->notificationService->sendRegistrationNotifications($registration->fresh(['event.venue', 'member']), false);
        } catch (\Throwable) {
          // Non-blocking.
        }
      }

      return $payment->fresh(['registration', 'event']);
    });
  }

  /**
   * @return array<string, mixed>
   */
  public function paymentPayload(EventRegistrationPayment $payment): array
  {
    return (new \App\Modules\Events\Http\Resources\EventRegistrationPaymentResource($payment))->resolve();
  }

  private function mapPaymentMethod(PaymentMethod $method): PaymentMethodType
  {
    return match ($method) {
      PaymentMethod::Offline, PaymentMethod::BankAccount, PaymentMethod::Wire => PaymentMethodType::Offline,
      default => PaymentMethodType::Gateway,
    };
  }

  private function resolveActor(EventRegistration $registration, ?User $user): User
  {
    if ($user !== null) {
      return $user;
    }

    if ($registration->created_by_user_id !== null) {
      $creator = User::query()->find($registration->created_by_user_id);
      if ($creator !== null) {
        return $creator;
      }
    }

    return User::query()->firstOrFail();
  }

  private function activateFromWaivedRegistration(EventRegistration $registration, ?User $actor): void
  {
    if ($registration->status !== RegistrationStatus::Submitted) {
      return;
    }

    $registration->fill([
      'status' => RegistrationStatus::Approved,
      'approved_at' => now(),
      'approved_by_user_id' => $actor?->id,
    ])->save();

    if ($registration->event?->check_in_enabled && ! $registration->checkInToken()->exists()) {
      $this->checkInTokenService->issue($registration, null, $actor);
    }
  }
}
