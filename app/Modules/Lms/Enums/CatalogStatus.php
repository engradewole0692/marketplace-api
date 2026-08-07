<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CatalogStatus: string
{
  case Active = 'active';
  case Inactive = 'inactive';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}