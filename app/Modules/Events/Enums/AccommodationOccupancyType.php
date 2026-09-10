<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum AccommodationOccupancyType: string
{
    case Private = 'private';
    case Shared = 'shared';
}
