<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CourseStatus: string
{
  case Draft = 'draft';
  case Published = 'published';
  case Archived = 'archived';
  case ComingSoon = 'coming_soon';
  case Hidden = 'hidden';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}