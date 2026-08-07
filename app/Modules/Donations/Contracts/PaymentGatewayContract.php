<?php

declare(strict_types=1);

namespace App\Modules\Donations\Contracts;

use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use Illuminate\Http\Request;

interface PaymentGatewayContract
{
  public function key(): string;

  /**
   * @param  array<string, mixed>  $context
   * @return array{type: string, redirect_url?: string|null, client_secret?: string|null, instructions?: array<string, mixed>|null, provider_intent_id?: string|null}
   */
  public function createCheckout(Donation $donation, array $context = []): array;

  /**
   * @return array{event: string, provider_payment_id?: string|null, status?: string|null, payload?: array<string, mixed>}
   */
  public function parseWebhook(Request $request): array;

  public function refund(DonationPayment $payment, ?float $amount = null): bool;

  public function supportsRecurring(): bool;
}
