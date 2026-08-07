<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberStatus: string
{
  case Draft = 'draft';
  case ApplicationSubmitted = 'application_submitted';
  case PendingReview = 'pending_review';
  case UnderReview = 'under_review';
  case InterviewRequired = 'interview_required';
  case InterviewScheduled = 'interview_scheduled';
  case InterviewInvitationSent = 'interview_invitation_sent';
  case InterviewConfirmed = 'interview_confirmed';
  case InterviewCompleted = 'interview_completed';
  case InterviewPassed = 'interview_passed';
  case InterviewFailed = 'interview_failed';
  case InterviewRescheduled = 'interview_rescheduled';
  case Approved = 'approved';
  case Rejected = 'rejected';
  case Orientation = 'orientation';
  case MinistryAssigned = 'ministry_assigned';
  case OnboardingCompleted = 'onboarding_completed';
  case Active = 'active';
  case Suspended = 'suspended';
  case Inactive = 'inactive';
  case Archived = 'archived';

  /**
   * @return list<self>
   */
  public static function workflowOrder(): array
  {
    return [
      self::Draft,
      self::ApplicationSubmitted,
      self::PendingReview,
      self::UnderReview,
      self::InterviewRequired,
      self::InterviewScheduled,
      self::InterviewInvitationSent,
      self::InterviewConfirmed,
      self::InterviewCompleted,
      self::InterviewPassed,
      self::InterviewFailed,
      self::InterviewRescheduled,
      self::Approved,
      self::Orientation,
      self::MinistryAssigned,
      self::OnboardingCompleted,
      self::Active,
      self::Suspended,
      self::Inactive,
      self::Archived,
    ];
  }

  /**
   * @return list<self>
   */
  public static function applicationPipeline(): array
  {
    return [
      self::Draft,
      self::ApplicationSubmitted,
      self::PendingReview,
      self::UnderReview,
      self::InterviewRequired,
      self::InterviewScheduled,
      self::InterviewInvitationSent,
      self::InterviewConfirmed,
      self::InterviewCompleted,
      self::InterviewPassed,
      self::InterviewFailed,
      self::InterviewRescheduled,
      self::Approved,
      self::Orientation,
      self::MinistryAssigned,
      self::OnboardingCompleted,
    ];
  }

  public function label(): string
  {
    return match ($this) {
      self::Draft => 'Draft',
      self::ApplicationSubmitted => 'Submitted',
      self::PendingReview => 'Pending Review',
      self::UnderReview => 'Under Review',
      self::InterviewRequired => 'Awaiting Interview',
      self::InterviewScheduled => 'Interview Scheduled',
      self::InterviewInvitationSent => 'Interview Invitation Sent',
      self::InterviewConfirmed => 'Interview Confirmed',
      self::InterviewCompleted => 'Interview Completed',
      self::InterviewPassed => 'Passed Interview',
      self::InterviewFailed => 'Failed Interview',
      self::InterviewRescheduled => 'Interview Rescheduled',
      self::Approved => 'Approved',
      self::Rejected => 'Rejected',
      self::Orientation => 'Onboarding',
      self::MinistryAssigned => 'Ministry Assigned',
      self::OnboardingCompleted => 'Onboarding Completed',
      self::Active => 'Active Member',
      self::Suspended => 'Suspended',
      self::Inactive => 'Inactive',
      self::Archived => 'Archived',
    };
  }

  public function canTransitionTo(self $target): bool
  {
    if ($this === $target) {
      return false;
    }

    return match ($this) {
      self::Draft => in_array($target, [self::ApplicationSubmitted, self::Archived], true),
      self::ApplicationSubmitted => in_array($target, [self::PendingReview, self::UnderReview, self::Archived, self::Rejected], true),
      self::PendingReview => in_array($target, [self::UnderReview, self::InterviewRequired, self::Rejected, self::Archived], true),
      self::UnderReview => in_array($target, [self::InterviewRequired, self::Rejected, self::PendingReview, self::Archived], true),
      self::InterviewRequired => in_array($target, [self::InterviewScheduled, self::InterviewInvitationSent, self::Rejected, self::UnderReview], true),
      self::InterviewScheduled => in_array($target, [
        self::InterviewInvitationSent,
        self::InterviewConfirmed,
        self::InterviewCompleted,
        self::InterviewPassed,
        self::InterviewFailed,
        self::InterviewRescheduled,
        self::InterviewRequired,
        self::Rejected,
      ], true),
      self::InterviewInvitationSent => in_array($target, [
        self::InterviewConfirmed,
        self::InterviewCompleted,
        self::InterviewPassed,
        self::InterviewFailed,
        self::InterviewRescheduled,
        self::InterviewScheduled,
        self::Rejected,
      ], true),
      self::InterviewConfirmed => in_array($target, [
        self::InterviewCompleted,
        self::InterviewPassed,
        self::InterviewFailed,
        self::InterviewRescheduled,
        self::Rejected,
      ], true),
      self::InterviewCompleted => in_array($target, [
        self::InterviewPassed,
        self::InterviewFailed,
        self::Approved,
        self::Rejected,
        self::InterviewScheduled,
        self::InterviewRescheduled,
      ], true),
      self::InterviewPassed => in_array($target, [self::Approved, self::Orientation, self::Active, self::Rejected], true),
      self::InterviewFailed => in_array($target, [
        self::InterviewRescheduled,
        self::InterviewScheduled,
        self::InterviewRequired,
        self::Rejected,
        self::UnderReview,
      ], true),
      self::InterviewRescheduled => in_array($target, [
        self::InterviewScheduled,
        self::InterviewInvitationSent,
        self::InterviewConfirmed,
        self::Rejected,
      ], true),
      self::Approved => in_array($target, [self::Orientation, self::MinistryAssigned, self::OnboardingCompleted, self::Active, self::Rejected, self::UnderReview], true),
      self::Orientation => in_array($target, [self::MinistryAssigned, self::OnboardingCompleted, self::Active, self::Approved], true),
      self::MinistryAssigned => in_array($target, [self::OnboardingCompleted, self::Active, self::Orientation], true),
      self::OnboardingCompleted => in_array($target, [self::Active, self::MinistryAssigned], true),
      self::Active => in_array($target, [self::Suspended, self::Inactive, self::Archived], true),
      self::Suspended => in_array($target, [self::Active, self::Inactive, self::Archived], true),
      self::Inactive => in_array($target, [self::Active, self::Archived], true),
      self::Archived => in_array($target, [self::Active, self::Inactive, self::ApplicationSubmitted], true),
      self::Rejected => in_array($target, [self::ApplicationSubmitted, self::PendingReview, self::Archived], true),
    };
  }
}
