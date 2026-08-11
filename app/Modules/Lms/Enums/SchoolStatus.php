<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum SchoolStatus: string
{
  case Draft = 'draft';
  case Published = 'published';
  case Archived = 'archived';
  case ComingSoon = 'coming_soon';
}
