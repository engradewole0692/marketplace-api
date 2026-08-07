<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum ReviewStatus: string
{
  case Pending = 'pending';
  case Approved = 'approved';
  case Rejected = 'rejected';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}