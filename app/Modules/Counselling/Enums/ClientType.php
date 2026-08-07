<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum ClientType: string
{
  case Visitor = 'visitor';
  case Member = 'member';
}
