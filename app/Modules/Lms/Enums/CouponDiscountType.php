<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CouponDiscountType: string
{
  case Percent = 'percent';
  case Fixed = 'fixed';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}