<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum AnnouncementStatus: string
{
  case Draft = 'draft';
  case Published = 'published';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}