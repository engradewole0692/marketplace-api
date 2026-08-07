<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AssignmentSubmissionStatus: string
{
  case Pending = 'pending';
  case Submitted = 'submitted';
  case Returned = 'returned';
  case Passed = 'passed';
  case Failed = 'failed';

  public function label(): string
  {
    return ucfirst($this->value);
  }

  public function isOpen(): bool
  {
    return in_array($this, [self::Pending, self::Returned], true);
  }
}
