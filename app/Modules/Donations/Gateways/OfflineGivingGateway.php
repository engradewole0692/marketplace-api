<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;

final class OfflineGivingGateway implements PaymentGatewayContract
{
  public function key(): string
  {
    return 'offline';
  }

  public function createCheckout(Donation $donation, array $context = []): array
  {
    return [
      'type' => 'instructions',
      'provider_intent_id' => $donation->reference,
      'instructions' => [
        'title' => 'Offline giving',
        'steps' => [
          'Complete your gift using the instructions below or await confirmation from our team.',
          'Use reference '.$donation->reference.' when transferring.',
          'You will receive a receipt after confirmation.',
        ],
        'reference' => $donation->reference,
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
