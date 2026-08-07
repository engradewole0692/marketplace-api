<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AssessmentType: string
{
  case Quiz = 'quiz';
  case Assignment = 'assignment';
  case TimedTest = 'timed_test';
  case Examination = 'examination';
  case Practical = 'practical';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
