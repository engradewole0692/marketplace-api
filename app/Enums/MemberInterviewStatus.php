<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberInterviewStatus: string
{
  case Pending = 'pending';
  case Scheduled = 'scheduled';
  case InvitationSent = 'invitation_sent';
  case Confirmed = 'confirmed';
  case Completed = 'completed';
  case Passed = 'passed';
  case Failed = 'failed';
  case Missed = 'missed';
  case Cancelled = 'cancelled';
  case Rescheduled = 'rescheduled';
  case AwaitingReview = 'awaiting_review';

  public function label(): string
  {
    return match ($this) {
      self::Pending => 'Pending',
      self::Scheduled => 'Scheduled',
      self::InvitationSent => 'Invitation Sent',
      self::Confirmed => 'Applicant Confirmed',
      self::Completed => 'Interview Completed',
      self::Passed => 'Passed',
      self::Failed => 'Failed',
      self::Missed => 'Missed',
      self::Cancelled => 'Cancelled',
      self::Rescheduled => 'Rescheduled',
      self::AwaitingReview => 'Awaiting Review',
    };
  }
}
