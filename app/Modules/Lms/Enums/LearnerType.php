<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum LearnerType: string
{
  case Member = 'member';
  case Public = 'public';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}