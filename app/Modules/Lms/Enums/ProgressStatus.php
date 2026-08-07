<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum ProgressStatus: string
{
  case NotStarted = 'not_started';
  case InProgress = 'in_progress';
  case Completed = 'completed';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}