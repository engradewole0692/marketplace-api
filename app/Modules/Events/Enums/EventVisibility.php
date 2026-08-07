<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventVisibility: string
{
  case Public = 'public';
  case Private = 'private';
  case Unlisted = 'unlisted';

  public function label(): string
  {
    return match ($this) {
      self::Public => 'Public',
      self::Private => 'Private',
      self::Unlisted => 'Unlisted',
    };
  }
}
