<?php

declare(strict_types=1);

namespace App\Modules\Communications\Enums;

enum EmailLogStatus: string
{
  case Queued = 'queued';
  case Sent = 'sent';
  case Failed = 'failed';
  case Skipped = 'skipped';

  public function label(): string
  {
    return match ($this) {
      self::Queued => 'Queued',
      self::Sent => 'Sent',
      self::Failed => 'Failed',
      self::Skipped => 'Skipped',
    };
  }
}
