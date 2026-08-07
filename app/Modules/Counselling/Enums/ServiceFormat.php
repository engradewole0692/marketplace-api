<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum ServiceFormat: string
{
  case Physical = 'physical';
  case Virtual = 'virtual';
  case Hybrid = 'hybrid';
  case Phone = 'phone';
  case Video = 'video';
}
