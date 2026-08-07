<?php

declare(strict_types=1);

namespace App\Enums;

enum BulkUserAction: string
{
  case Delete = 'delete';
  case Restore = 'restore';
  case Activate = 'activate';
  case Deactivate = 'deactivate';
}
