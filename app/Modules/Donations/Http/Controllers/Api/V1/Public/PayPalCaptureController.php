<?php

declare(strict_types=1);

namespace App\Modules\Donations\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Donations\Gateways\PayPalGateway;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Services\DonationCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called from the frontend after PayPal redirects the buyer back.
 * Frontend passes the PayPal order ID; we capture server-side and confirm.
 */
final class PayPalCaptureController extends ApiController
{
  public function capture(
    Request $request,
    DonationCheckoutService $checkoutService,
    PayPalGateway $paypal,
  ): JsonResponse {
    $request->validate([
      'token' => ['required', 'string'],    // PayPal order ID (in the return URL as ?token=...)
      'donation_id' => ['required', 'string'],
    ]);

    $donation = Donation::query()
      ->where('uuid', $request->input('donation_id'))
      ->orWhere('provider_intent_id', $request->input('token'))
      ->firstOrFail();

    // Idempotency: already succeeded means we already processed this.
    if ($donation->status->value === 'succeeded') {
      return $this->responder->success(
        data: ['donation' => $donation->fresh(['fund', 'country', 'payments'])],
        message: 'Payment already confirmed.',
      );
    }

    $orderId = (string) ($request->input('token') ?? $donation->provider_intent_id ?? '');

    $capture = $paypal->captureOrder($orderId, $donation->country_id);

    $status = $capture['status'] ?? '';
    if (in_array($status, ['completed', 'captured', 'succeeded'], true)) {
      $donation = $checkoutService->confirmSucceeded($donation, $request->user(), $capture);
    }

    return $this->responder->success(
      data: ['donation' => $donation->fresh(['fund', 'country', 'payments'])],
      message: 'Payment captured.',
    );
  }
}
