<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

enum TestimonialStatus: string
{
  case Pending = 'pending';
  case Approved = 'approved';
  case Rejected = 'rejected';
}
