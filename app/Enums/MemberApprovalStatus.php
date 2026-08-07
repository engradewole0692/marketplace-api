<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberApprovalStatus: string
{
  case Pending = 'pending';
  case UnderReview = 'under_review';
  case Approved = 'approved';
  case Rejected = 'rejected';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::UnderReview => 'Under Review',
      self::Approved => 'Approved',
      self::Rejected => 'Rejected',
    };
  }
}
