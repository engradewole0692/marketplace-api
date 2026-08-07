<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum PaymentStatus: string
{
  case Pending = 'pending';
  case Paid = 'paid';
  case Refunded = 'refunded';
  case Failed = 'failed';
  case Cancelled = 'cancelled';
}
