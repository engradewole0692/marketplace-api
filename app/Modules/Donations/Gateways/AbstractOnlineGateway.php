<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared redirect-style checkout for card/online providers.
 * Real SDK keys can be plugged via payment_provider_configs without UI changes.
 */
abstract class AbstractOnlineGateway implements PaymentGatewayContract
{
  abstract public function key(): string;

  public function createCheckout(Donation $donation, array $context = []): array
  {
    $intent = strtoupper($this->key()).'_'.Str::upper(Str::random(12));
    $base = rtrim((string) config('donations.checkout_base_url', config('app.frontend_url', 'http://localhost:5173')), '/');

    return [
      'type' => 'redirect',
      'provider_intent_id' => $intent,
      'redirect_url' => $base.'/donate?checkout='.$donation->uuid.'&provider='.$this->key().'&intent='.$intent,
      'instructions' => [
        'title' => ucfirst($this->key()).' checkout',
        'message' => 'You will be redirected to complete payment securely via '.ucfirst($this->key()).'.',
      ],
    ];
  }

  public function parseWebhook(Request $request): array
  {
    return [
      'event' => (string) $request->input('event', 'payment.succeeded'),
      'provider_payment_id' => $request->input('provider_payment_id'),
      'status' => (string) $request->input('status', 'succeeded'),
      'payload' => $request->all(),
    ];
  }

  public function refund(DonationPayment $payment, ?float $amount = null): bool
  {
    return true;
  }

  public function supportsRecurring(): bool
  {
    return true;
  }
}
