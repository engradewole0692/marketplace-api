<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum CouponDiscountType: string
{
  case Percent = 'percent';
  case Fixed = 'fixed';

  public function label(): string
  {
    return match ($this) {
      self::Percent => 'Percent',
      self::Fixed => 'Fixed',
    };
  }
}
