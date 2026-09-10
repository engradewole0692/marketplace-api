<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum AttendanceMode: string
{
    case Single = 'single';
    case Daily = 'daily';
}
