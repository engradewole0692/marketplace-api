<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AttemptStatus: string
{
  case InProgress = 'in_progress';
  case Submitted = 'submitted';
  case Grading = 'grading';
  case Graded = 'graded';
  case Expired = 'expired';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
