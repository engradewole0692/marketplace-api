<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CourseAccessScope: string
{
  case General = 'general';
  case Ministry = 'ministry';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
