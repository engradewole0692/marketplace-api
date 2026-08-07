<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AssignmentType: string
{
  case Objective = 'objective';
  case Essay = 'essay';
  case Upload = 'upload';
  case Mixed = 'mixed';

  public function label(): string
  {
    return ucfirst($this->value);
  }
}
