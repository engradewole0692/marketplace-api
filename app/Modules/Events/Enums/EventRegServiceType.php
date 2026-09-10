<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum EventRegServiceType: string
{
    case Accommodation = 'accommodation';
    case Transport = 'transport';
    case Travel = 'travel';
}
