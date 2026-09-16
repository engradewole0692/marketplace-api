<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum SeatingArea: string
{
    case MainHall = 'main_hall';
    case Overflow = 'overflow';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::MainHall => 'Main Hall',
            self::Overflow => 'Overflow',
            self::None => 'Not seat-counted',
        };
    }
}
