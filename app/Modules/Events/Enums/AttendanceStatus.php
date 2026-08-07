<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum AttendanceStatus: string
{
  case Present = 'present';
  case Absent = 'absent';
  case Excused = 'excused';
  case Late = 'late';
  case CheckedOut = 'checked_out';

  public function label(): string
  {
    return match ($this) {
      self::Present => 'Present',
      self::Absent => 'Absent',
      self::Excused => 'Excused',
      self::Late => 'Late',
      self::CheckedOut => 'Checked Out',
    };
  }
}
