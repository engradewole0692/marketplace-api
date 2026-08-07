<?php

declare(strict_types=1);

namespace App\Modules\Donations\Enums;

enum DonationFrequency: string
{
  case OneTime = 'one_time';
  case Monthly = 'monthly';
  case Quarterly = 'quarterly';
  case Yearly = 'yearly';
}
