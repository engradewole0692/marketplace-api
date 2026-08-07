<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum PageStatus: string
{
  case Draft = 'draft';
  case Review = 'review';
  case Published = 'published';
  case Scheduled = 'scheduled';
  case Archived = 'archived';
}
