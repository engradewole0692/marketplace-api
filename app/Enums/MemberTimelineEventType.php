<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberTimelineEventType: string
{
  case MemberCreated = 'member_created';
  case StatusChanged = 'status_changed';
  case Approved = 'approved';
  case Rejected = 'rejected';
  case AssignedMinistry = 'assigned_ministry';
  case AssignedCountry = 'assigned_country';
  case AssignedRegion = 'assigned_region';
  case Promoted = 'promoted';
  case NoteAdded = 'note_added';
  case DocumentUploaded = 'document_uploaded';
  case TagAssigned = 'tag_assigned';
  case CourseCompleted = 'course_completed';
  case CertificateAwarded = 'certificate_awarded';
  case DonationRecorded = 'donation_recorded';
  case PrayerRequestSubmitted = 'prayer_request_submitted';
  case BulkAction = 'bulk_action';
  case InterviewScheduled = 'interview_scheduled';
  case InterviewInvitationSent = 'interview_invitation_sent';
  case InterviewConfirmed = 'interview_confirmed';
  case InterviewCompleted = 'interview_completed';
  case InterviewPassed = 'interview_passed';
  case InterviewFailed = 'interview_failed';
  case InterviewRescheduled = 'interview_rescheduled';
  case CredentialsSent = 'credentials_sent';
  case ApplicationSubmitted = 'application_submitted';
  case ReviewStarted = 'review_started';
  case Activated = 'activated';
  case OnboardingCompleted = 'onboarding_completed';
  case AccountCreated = 'account_created';

  public function label(): string
  {
    return str_replace('_', ' ', ucfirst($this->value));
  }
}
