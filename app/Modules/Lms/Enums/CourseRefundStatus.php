<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CourseRefundStatus: string
{
  case Pending = 'pending';
  case Approved = 'approved';
  case Rejected = 'rejected';
  case Processed = 'processed';
  case Failed = 'failed';

  public function label(): string
  {
    return ucfirst($this->value);
  }
}
