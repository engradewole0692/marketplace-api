<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

final class CardGateway extends AbstractOnlineGateway
{
  public function key(): string
  {
    return 'card';
  }
}
