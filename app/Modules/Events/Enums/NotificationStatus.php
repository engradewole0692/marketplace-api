<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum NotificationStatus: string
{
  case Pending = 'pending';
  case Queued = 'queued';
  case Sent = 'sent';
  case Failed = 'failed';
  case Cancelled = 'cancelled';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::Queued => 'Queued',
      self::Sent => 'Sent',
      self::Failed => 'Failed',
      self::Cancelled => 'Cancelled',
    };
  }
}
