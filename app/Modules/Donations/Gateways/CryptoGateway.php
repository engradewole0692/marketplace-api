<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;
use RuntimeException;

/** Future-ready crypto gateway stub. */
final class CryptoGateway implements PaymentGatewayContract
{
  public function key(): string
  {
    return 'crypto';
  }

  public function createCheckout(Donation $donation, array $context = []): array
  {
    if (! config('donations.crypto_enabled', false)) {
      throw new RuntimeException('Crypto donations are not enabled yet.');
    }

    return [
      'type' => 'instructions',
      'provider_intent_id' => $donation->reference,
      'instructions' => [
        'title' => 'Crypto giving (preview)',
        'message' => 'Crypto rails will be activated in a future release.',
      ],
    ];
  }

  public function parseWebhook(Request $request): array
  {
    return ['event' => 'noop', 'payload' => $request->all()];
  }

  public function refund(DonationPayment $payment, ?float $amount = null): bool
  {
    return false;
  }

  public function supportsRecurring(): bool
  {
    return false;
  }
}
