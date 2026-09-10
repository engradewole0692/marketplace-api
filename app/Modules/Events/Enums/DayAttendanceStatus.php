<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum DayAttendanceStatus: string
{
    case NotAttended = 'not_attended';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Absent = 'absent';

    public function countsAsAttended(): bool
    {
        return in_array($this, [self::CheckedIn, self::CheckedOut], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotAttended => 'Not Attended',
            self::CheckedIn => 'Checked In',
            self::CheckedOut => 'Checked Out',
            self::Absent => 'Absent',
        };
    }
}
