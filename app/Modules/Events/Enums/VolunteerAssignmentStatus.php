<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum VolunteerAssignmentStatus: string
{
  case Interested = 'interested';
  case Assigned = 'assigned';
  case Confirmed = 'confirmed';
  case Completed = 'completed';
  case Cancelled = 'cancelled';

  public function label(): string
  {
    return match ($this) {
      self::Interested => 'Interested',
      self::Assigned => 'Assigned',
      self::Confirmed => 'Confirmed',
      self::Completed => 'Completed',
      self::Cancelled => 'Cancelled',
    };
  }
}
