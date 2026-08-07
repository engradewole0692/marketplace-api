<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;

final class WireTransferGateway implements PaymentGatewayContract
{
  public function key(): string
  {
    return 'wire';
  }

  public function createCheckout(Donation $donation, array $context = []): array
  {
    return [
      'type' => 'instructions',
      'provider_intent_id' => $donation->reference,
      'instructions' => [
        'title' => 'Wire transfer',
        'reference' => $donation->reference,
        'steps' => [
          'Initiate an international wire for '.$donation->currency.' '.$donation->amount.'.',
          'Use reference '.$donation->reference.'.',
          'Bank details are provided by our finance office for your country.',
        ],
        'contact_email' => config('donations.finance_email'),
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
