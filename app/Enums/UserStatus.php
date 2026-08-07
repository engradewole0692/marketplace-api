<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
  case Active = 'active';
  case Inactive = 'inactive';
  case Suspended = 'suspended';
  case Pending = 'pending';

  public function label(): string
  {
    return match ($this) {
      self::Active => 'Active',
      self::Inactive => 'Inactive',
      self::Suspended => 'Suspended',
      self::Pending => 'Pending',
    };
  }

  public function canAuthenticate(): bool
  {
    return $this === self::Active;
  }
}
