<?php

declare(strict_types=1);

namespace App\Modules\Donations\Enums;

enum ReceiptType: string
{
  case Standard = 'standard';
  case Tax = 'tax';
}
