<?php

declare(strict_types=1);

namespace App\Modules\Donations\Services;

use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Gateways\BankAccountGateway;
use App\Modules\Donations\Gateways\CardGateway;
use App\Modules\Donations\Gateways\CryptoGateway;
use App\Modules\Donations\Gateways\FlutterwaveGateway;
use App\Modules\Donations\Gateways\OfflineGivingGateway;
use App\Modules\Donations\Gateways\PayPalGateway;
use App\Modules\Donations\Gateways\PaystackGateway;
use App\Modules\Donations\Gateways\StripeGateway;
use App\Modules\Donations\Gateways\WireTransferGateway;
use InvalidArgumentException;

final class PaymentGatewayManager
{
  public function for(PaymentMethod|string $method): PaymentGatewayContract
  {
    $key = $method instanceof PaymentMethod ? $method->value : $method;

    return match ($key) {
      'bank_account' => app(BankAccountGateway::class),
      'card' => app(CardGateway::class),
      'flutterwave' => app(FlutterwaveGateway::class),
      'paystack' => app(PaystackGateway::class),
      'stripe' => app(StripeGateway::class),
      'paypal' => app(PayPalGateway::class),
      'offline' => app(OfflineGivingGateway::class),
      'wire' => app(WireTransferGateway::class),
      'crypto' => app(CryptoGateway::class),
      default => throw new InvalidArgumentException("Unknown payment method [{$key}]."),
    };
  }
}
