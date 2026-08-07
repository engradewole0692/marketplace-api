<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

enum RegistrationStatus: string
{
  case Submitted = 'submitted';
  case PendingReview = 'pending_review';
  case Approved = 'approved';
  case Waitlisted = 'waitlisted';
  case CheckedIn = 'checked_in';
  case Attended = 'attended';
  case Cancelled = 'cancelled';
  case Declined = 'declined';
  case NoShow = 'no_show';

  public function label(): string
  {
    return match ($this) {
      self::Submitted => 'Submitted',
      self::PendingReview => 'Pending Review',
      self::Approved => 'Approved',
      self::Waitlisted => 'Waitlisted',
      self::CheckedIn => 'Checked In',
      self::Attended => 'Attended',
      self::Cancelled => 'Cancelled',
      self::Declined => 'Declined',
      self::NoShow => 'No Show',
    };
  }

  public function canTransitionTo(self $target): bool
  {
    if ($this === $target) {
      return false;
    }

    return match ($this) {
      self::Submitted => in_array($target, [self::PendingReview, self::Approved, self::Waitlisted, self::Cancelled, self::Declined], true),
      self::PendingReview => in_array($target, [self::Approved, self::Waitlisted, self::Cancelled, self::Declined], true),
      self::Approved => in_array($target, [self::CheckedIn, self::Attended, self::Cancelled, self::NoShow], true),
      self::Waitlisted => in_array($target, [self::Approved, self::Cancelled, self::Declined], true),
      self::CheckedIn => in_array($target, [self::Attended, self::NoShow], true),
      self::Attended => false,
      self::Cancelled => false,
      self::Declined => false,
      self::NoShow => in_array($target, [self::Attended], true),
    };
  }
}
