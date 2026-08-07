<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum PaymentStatus: string
{
  case Pending = 'pending';
  case Approved = 'approved';
  case Paid = 'paid';
  case Failed = 'failed';
  case Waived = 'waived';
  case Refunded = 'refunded';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::Approved => 'Approved',
      self::Paid => 'Paid',
      self::Failed => 'Failed',
      self::Waived => 'Waived',
      self::Refunded => 'Refunded',
    };
  }
}
