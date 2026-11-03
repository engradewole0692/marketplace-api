<?php

declare(strict_types=1);

namespace App\Modules\Donations\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Gateways\PayPalGateway;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use App\Modules\Lms\Models\SchoolOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called from the frontend after PayPal redirects the buyer back.
 *
 * The browser redirect is not proof of payment: the order is captured server-side and confirmed only
 * when PayPal reports a COMPLETED capture whose amount, currency and reference match the internal
 * payment record. Confirmation is idempotent.
 */
final class PayPalCaptureController extends ApiController
{
  public function capture(
    Request $request,
    DonationCheckoutService $checkoutService,
    PayPalGateway $paypal,
  ): JsonResponse {
    $validated = $request->validate([
      'token' => ['required', 'string', 'max:64'],   // PayPal order ID (?token=… in the return URL)
      'donation_id' => ['required', 'uuid'],
    ]);

    $donation = Donation::query()->where('uuid', $validated['donation_id'])->firstOrFail();

    if ((string) $donation->provider_intent_id === '' || ! hash_equals((string) $donation->provider_intent_id, $validated['token'])) {
      return $this->responder->error('This PayPal order does not belong to the payment being confirmed.', 'PAYMENT_MISMATCH', 422);
    }

    if ($donation->status === DonationStatus::Succeeded) {
      return $this->result($donation, 'confirmed', 'Payment already confirmed.');
    }

    // Cancelled (e.g. superseded by repricing), failed or refunded payments are never captured.
    if (in_array($donation->status, [DonationStatus::Cancelled, DonationStatus::Failed, DonationStatus::Refunded], true)) {
      return $this->responder->error('This payment is no longer payable. Start a new checkout to continue.', 'PAYMENT_NOT_PAYABLE', 422);
    }

    $capture = $paypal->captureOrder($validated['token'], $donation->country_id);

    if ($capture['custom_id'] !== null && $capture['custom_id'] !== $donation->uuid) {
      return $this->responder->error('PayPal returned a different payment reference. The payment was not confirmed.', 'PAYMENT_MISMATCH', 422);
    }

    if ($capture['captured']) {
      $checkoutService->assertAmountMatches($donation, $capture['amount'], $capture['currency']);
      $donation = $checkoutService->confirmSucceeded($donation, $request->user(), $capture);

      return $this->result($donation, 'confirmed', 'Payment captured and verified.');
    }

    $status = in_array($capture['capture_status'], ['declined', 'failed'], true) ? 'failed' : 'pending';
    $payment = $donation->payments()->latest()->first();
    $payment?->fill(['raw_payload' => array_merge($payment->raw_payload ?? [], ['last_capture' => $capture])])->save();

    return $this->result(
      $donation->fresh() ?? $donation,
      $status,
      $status === 'failed'
        ? 'PayPal did not complete this payment. No charge was confirmed.'
        : 'PayPal has not completed this payment yet. Access is granted once PayPal confirms it.',
    );
  }

  private function result(Donation $donation, string $paymentStatus, string $message): JsonResponse
  {
    $data = [
      'payment_status' => $paymentStatus,
      'payment_purpose' => $donation->payment_purpose ?? Donation::PURPOSE_DONATION,
      'donation' => $donation->fresh(['fund', 'country', 'payments']) ?? $donation,
    ];

    if (($donation->payment_purpose ?? null) === Donation::PURPOSE_SCHOOL_ENROLLMENT) {
      $order = SchoolOrder::query()->where('donation_id', $donation->id)->first()
        ?? SchoolOrder::query()->where('uuid', (string) (($donation->metadata ?? [])['school_order_uuid'] ?? ''))->first();
      $order?->loadMissing(['school:id,uuid,title,slug', 'schoolEnrollment']);
      $data['school_order'] = $order !== null ? [
        'order_number' => $order->order_number,
        'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status,
        'amount' => (float) $order->amount,
        'currency' => $order->currency,
        'pricing_category' => $order->pricing_category,
        'school' => $order->school ? ['title' => $order->school->title, 'slug' => $order->school->slug] : null,
        'enrollment_status' => $order->schoolEnrollment?->status instanceof \BackedEnum
          ? $order->schoolEnrollment->status->value
          : $order->schoolEnrollment?->status,
      ] : null;
    }

    return $this->responder->success(data: $data, message: $message);
  }
}
