<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum NoteVisibility: string
{
  case Counsellor = 'counsellor';
  case Client = 'client';
  case Admin = 'admin';
}
