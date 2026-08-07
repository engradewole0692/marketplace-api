<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum AppointmentStatus: string
{
  case Scheduled = 'scheduled';
  case Confirmed = 'confirmed';
  case Completed = 'completed';
  case Missed = 'missed';
  case Cancelled = 'cancelled';
  case Rescheduled = 'rescheduled';
}
