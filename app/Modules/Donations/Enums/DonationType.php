<?php

declare(strict_types=1);

namespace App\Modules\Donations\Enums;

enum DonationType: string
{
  case General = 'general';
  case Mission = 'mission';
  case Projects = 'projects';
  case Events = 'events';
  case Building = 'building';
  case Scholarship = 'scholarship';
}
