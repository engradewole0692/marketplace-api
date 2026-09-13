<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventStaffDomain: string
{
    case Operations = 'operations';
    case Accommodation = 'accommodation';
    case Logistics = 'logistics';
    case Travel = 'travel';
    case Finance = 'finance';

    public function permission(): string
    {
        return match ($this) {
            self::Operations => 'attendance.manage',
            self::Accommodation => 'accommodation.manage',
            self::Logistics => 'logistics.manage',
            self::Travel => 'travel.manage',
            self::Finance => 'event_payments.manage',
        };
    }
}
