<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

final class PaystackGateway extends AbstractOnlineGateway
{
  public function key(): string
  {
    return 'paystack';
  }
}
