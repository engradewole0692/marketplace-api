<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

final class FlutterwaveGateway extends AbstractOnlineGateway
{
  public function key(): string
  {
    return 'flutterwave';
  }
}
