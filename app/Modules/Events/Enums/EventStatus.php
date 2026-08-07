<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventStatus: string
{
  case Draft = 'draft';
  case Published = 'published';
  case Open = 'open';
  case Closed = 'closed';
  case Completed = 'completed';
  case Cancelled = 'cancelled';
  case Archived = 'archived';

  public function label(): string
  {
    return match ($this) {
      self::Draft => 'Draft',
      self::Published => 'Published',
      self::Open => 'Open',
      self::Closed => 'Closed',
      self::Completed => 'Completed',
      self::Cancelled => 'Cancelled',
      self::Archived => 'Archived',
    };
  }

  public function acceptsRegistrations(): bool
  {
    return in_array($this, [self::Published, self::Open], true);
  }
}
