<?php

declare(strict_types=1);

namespace App\Enums;

enum BulkMemberAction: string
{
  case Approve = 'approve';
  case Reject = 'reject';
  case Activate = 'activate';
  case Deactivate = 'deactivate';
  case Archive = 'archive';
  case Delete = 'delete';
  case Restore = 'restore';
}
