<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum ExportStatus: string
{
  case Pending = 'pending';
  case Processing = 'processing';
  case Completed = 'completed';
  case Failed = 'failed';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::Processing => 'Processing',
      self::Completed => 'Completed',
      self::Failed => 'Failed',
    };
  }
}
