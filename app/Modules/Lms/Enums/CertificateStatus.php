<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CertificateStatus: string
{
  case Pending = 'pending';
  case Issued = 'issued';
  case Revoked = 'revoked';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}