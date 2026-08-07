<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AssessmentStatus: string
{
  case Draft = 'draft';
  case Published = 'published';
  case Archived = 'archived';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
