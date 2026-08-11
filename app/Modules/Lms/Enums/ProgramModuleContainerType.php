<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum ProgramModuleContainerType: string
{
  case School = 'school';
  case Category = 'category';

  public function label(): string
  {
    return match ($this) {
      self::School => 'School',
      self::Category => 'Free category',
    };
  }
}
