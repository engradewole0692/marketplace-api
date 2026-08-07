<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberAuditEventType: string
{
  case MemberCreated = 'member_created';
  case MemberUpdated = 'member_updated';
  case MemberDeleted = 'member_deleted';
  case MemberRestored = 'member_restored';
  case MemberApproved = 'member_approved';
  case MemberRejected = 'member_rejected';
  case StatusChanged = 'status_changed';
  case NoteCreated = 'note_created';
  case NoteDeleted = 'note_deleted';
  case DocumentUploaded = 'document_uploaded';
  case DocumentDeleted = 'document_deleted';
  case BulkAction = 'bulk_action';
  case MembersExported = 'members_exported';
  case InterviewScheduled = 'interview_scheduled';
  case InterviewUpdated = 'interview_updated';
  case InterviewInvitationSent = 'interview_invitation_sent';
  case InterviewConfirmed = 'interview_confirmed';
  case InterviewPassed = 'interview_passed';
  case InterviewFailed = 'interview_failed';
  case InterviewRescheduled = 'interview_rescheduled';
  case CredentialsSent = 'credentials_sent';
  case MemberActivated = 'member_activated';
  case MinistryAssigned = 'ministry_assigned';
  case CountryAssigned = 'country_assigned';
  case OnboardingUpdated = 'onboarding_updated';
  case ApplicationSubmitted = 'application_submitted';
  case AwaitingInterviewReview = 'awaiting_interview_review';
}
