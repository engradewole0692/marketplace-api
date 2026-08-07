<?php

declare(strict_types=1);

namespace App\Modules\Lms\Enums;

enum CourseAudience: string
{
  case VisitorOnly = 'visitor_only';
  case MemberOnly = 'member_only';
  case Both = 'both';

  public function label(): string
  {
    return match ($this) {
      self::VisitorOnly => 'Visitor only',
      self::MemberOnly => 'Member only',
      self::Both => 'Visitor & Member',
    };
  }

  public function allowsVisitor(): bool
  {
    return $this === self::VisitorOnly || $this === self::Both;
  }

  public function allowsMember(): bool
  {
    return $this === self::MemberOnly || $this === self::Both;
  }
}
