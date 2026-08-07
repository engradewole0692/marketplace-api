<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum CertificateStatus: string
{
  case Pending = 'pending';
  case Issued = 'issued';
  case Revoked = 'revoked';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::Issued => 'Issued',
      self::Revoked => 'Revoked',
    };
  }
}
