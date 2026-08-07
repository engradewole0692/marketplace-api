<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum CheckInMethod: string
{
  case Manual = 'manual';
  case Qr = 'qr';
  case Kiosk = 'kiosk';
  case Import = 'import';

  public function label(): string
  {
    return match ($this) {
      self::Manual => 'Manual',
      self::Qr => 'QR',
      self::Kiosk => 'Kiosk',
      self::Import => 'Import',
    };
  }
}
