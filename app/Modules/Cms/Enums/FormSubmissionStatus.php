<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum FormSubmissionStatus: string
{
  case New = 'new';
  case Assigned = 'assigned';
  case Processing = 'processing';
  case Completed = 'completed';
  case Closed = 'closed';
  case Spam = 'spam';
}
