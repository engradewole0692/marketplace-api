<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum ResourceAccessLevel: string
{
  case Free = 'free';
  case MembersOnly = 'members_only';
  case Paid = 'paid';
  case PreviewOnly = 'preview_only';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
