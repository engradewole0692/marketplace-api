<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CourseOrderStatus: string
{
  case Pending = 'pending';
  case AwaitingPayment = 'awaiting_payment';
  case Paid = 'paid';
  case Failed = 'failed';
  case Cancelled = 'cancelled';
  case Refunded = 'refunded';
  case PartiallyRefunded = 'partially_refunded';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
