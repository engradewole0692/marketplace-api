<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;

final class BankAccountGateway implements PaymentGatewayContract
{
  public function key(): string
  {
    return 'bank_account';
  }

  public function createCheckout(Donation $donation, array $context = []): array
  {
    $accounts = DonationBankAccount::query()
      ->where('country_id', $donation->country_id)
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get()
      ->map(fn (DonationBankAccount $account) => [
        'bank_name' => $account->bank_name,
        'account_name' => $account->account_name,
        'account_number' => $account->account_number,
        'routing_number' => $account->routing_number,
        'swift_code' => $account->swift_code,
        'iban' => $account->iban,
        'currency' => $account->currency,
        'instructions' => $account->instructions,
      ])
      ->values()
      ->all();

    return [
      'type' => 'instructions',
      'provider_intent_id' => $donation->reference,
      'instructions' => [
        'title' => 'Bank transfer',
        'reference' => $donation->reference,
        'accounts' => $accounts,
        'steps' => [
          'Transfer '.$donation->currency.' '.$donation->amount.' to one of the accounts listed.',
          'Include reference '.$donation->reference.' in the payment narration.',
          'Our finance team will confirm and issue your receipt.',
        ],
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
