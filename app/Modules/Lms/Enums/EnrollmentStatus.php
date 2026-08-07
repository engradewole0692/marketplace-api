<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum EnrollmentStatus: string
{
  case Active = 'active';
  case Completed = 'completed';
  case Cancelled = 'cancelled';
  case Expired = 'expired';
  case PendingPayment = 'pending_payment';
  case Locked = 'locked';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }

  public function canAccessPlayer(): bool
  {
    return $this === self::Active || $this === self::Completed;
  }
}