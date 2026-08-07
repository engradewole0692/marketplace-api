<?php

declare(strict_types=1);

namespace App\Modules\Donations\Enums;

enum DonationStatus: string
{
  case Pending = 'pending';
  case Processing = 'processing';
  case Succeeded = 'succeeded';
  case Failed = 'failed';
  case Refunded = 'refunded';
  case Cancelled = 'cancelled';
}
