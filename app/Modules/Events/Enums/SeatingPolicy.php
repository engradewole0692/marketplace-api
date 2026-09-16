<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum SeatingPolicy: string
{
    case MainThenOverflow = 'main_then_overflow';
    case MainOnly = 'main_only';
    case OverflowOnly = 'overflow_only';
    case StaffSelect = 'staff_select';

    public static function fromEvent(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::MainThenOverflow;
    }
}
