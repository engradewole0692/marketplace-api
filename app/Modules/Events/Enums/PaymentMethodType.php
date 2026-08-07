<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum PaymentMethodType: string
{
  case Offline = 'offline';
  case Manual = 'manual';
  case Coupon = 'coupon';
  case Gateway = 'gateway';
  case Free = 'free';

  public function label(): string
  {
    return match ($this) {
      self::Offline => 'Offline',
      self::Manual => 'Manual',
      self::Coupon => 'Coupon',
      self::Gateway => 'Gateway',
      self::Free => 'Free',
    };
  }
}
