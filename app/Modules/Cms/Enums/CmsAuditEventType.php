<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum CmsAuditEventType: string
{
  case Created = 'created';
  case Updated = 'updated';
  case Deleted = 'deleted';
  case Restored = 'restored';
  case Published = 'published';
  case Archived = 'archived';
}
