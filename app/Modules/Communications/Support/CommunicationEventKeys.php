<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

/**
 * Canonical communication event keys. Dispatchers and seeded templates must use these strings.
 */
final class CommunicationEventKeys
{
  public const FORM_CONTACT_SUBMITTED = 'form.contact.submitted';
  public const FORM_CONTACT_SUBMITTED_ADMIN = 'form.contact.submitted.admin';
  public const FORM_PRAYER_SUBMITTED = 'form.prayer.submitted';
  public const FORM_PRAYER_SUBMITTED_ADMIN = 'form.prayer.submitted.admin';
  public const FORM_COUNSELING_SUBMITTED = 'form.counseling.submitted';
  public const FORM_COUNSELING_SUBMITTED_ADMIN = 'form.counseling.submitted.admin';
  public const FORM_MEMBERSHIP_SUBMITTED = 'form.membership.submitted';
  public const FORM_MEMBERSHIP_SUBMITTED_ADMIN = 'form.membership.submitted.admin';
  public const FORM_PARTNERSHIP_SUBMITTED = 'form.partnership.submitted';
  public const FORM_PARTNERSHIP_SUBMITTED_ADMIN = 'form.partnership.submitted.admin';
  public const FORM_NEWSLETTER_SUBMITTED = 'form.newsletter.submitted';
  public const FORM_NEWSLETTER_SUBMITTED_ADMIN = 'form.newsletter.submitted.admin';
  public const FORM_DONATION_SUBMITTED = 'form.donation.submitted';
  public const FORM_DONATION_SUBMITTED_ADMIN = 'form.donation.submitted.admin';
  public const FORM_VOLUNTEER_SUBMITTED = 'form.volunteer.submitted';
  public const FORM_VOLUNTEER_SUBMITTED_ADMIN = 'form.volunteer.submitted.admin';
  public const FORM_TESTIMONY_SUBMITTED = 'form.testimony.submitted';
  public const FORM_TESTIMONY_SUBMITTED_ADMIN = 'form.testimony.submitted.admin';

  public const AUTH_LEARNER_REGISTERED = 'auth.learner.registered';
  public const AUTH_PASSWORD_RESET = 'auth.password.reset';
  public const AUTH_EMAIL_VERIFICATION = 'auth.email.verification';

  public const FORM_BUSINESS_REVIEW_SUBMITTED = 'form.business-review.submitted';
  public const FORM_BUSINESS_REVIEW_SUBMITTED_ADMIN = 'form.business-review.submitted.admin';
  public const BUSINESS_REVIEW_STATUS_UPDATED = 'business-review.status.updated';

  public const LMS_ENROLLMENT_CREATED = 'lms.enrollment.created';
  public const LMS_SCHOOL_ENROLLMENT_CREATED = 'lms.school.enrollment.created';
  public const LMS_SCHOOL_ENROLLMENT_ACTIVATED = 'lms.school.enrollment.activated';
  public const LMS_PAYMENT_CONFIRMED = 'lms.payment.confirmed';
  public const LMS_SCHOOL_PAYMENT_CONFIRMED = 'lms.school.payment.confirmed';
  public const LMS_PAYMENT_OFFLINE_SUBMITTED = 'lms.payment.offline.submitted';
  public const LMS_PAYMENT_REJECTED = 'lms.payment.rejected';
  public const LMS_PAYMENT_REFUNDED = 'lms.payment.refunded';
  public const LMS_COURSE_COMPLETED = 'lms.course.completed';
  public const LMS_MODULE_COMPLETED = 'lms.module.completed';
  public const LMS_ASSIGNMENT_SUBMITTED = 'lms.assignment.submitted';
  public const LMS_ASSIGNMENT_GRADED = 'lms.assignment.graded';
  public const LMS_CERTIFICATE_ISSUED = 'lms.certificate.issued';
  public const LMS_ASSESSMENT_SUBMITTED = 'lms.assessment.submitted';
  public const LMS_ASSESSMENT_PASSED = 'lms.assessment.passed';
  public const LMS_ASSESSMENT_FAILED = 'lms.assessment.failed';
  public const LMS_TRANSCRIPT_AVAILABLE = 'lms.transcript.available';

  public const EVENT_REGISTRATION_CONFIRMED = 'event.registration.confirmed';
  public const EVENT_REGISTRATION_CONFIRMED_ADMIN = 'event.registration.confirmed.admin';
  public const EVENT_REGISTRATION_CANCELLED = 'event.registration.cancelled';
  public const EVENT_UPDATED = 'event.updated';
  public const EVENT_CANCELLED = 'event.cancelled';
  public const EVENT_REMINDER = 'event.reminder';
  public const EVENT_ANNOUNCEMENT = 'event.announcement';
  public const EVENT_CERTIFICATE_ISSUED = 'event.certificate.issued';
  public const EVENT_CHECK_IN_REMINDER = 'event.check_in.reminder';
  public const EVENT_VOLUNTEER_ASSIGNED = 'event.volunteer.assigned';
  public const EVENT_SCHEDULE_CHANGE = 'event.schedule.change';

  public const DONATION_INITIATED = 'donation.initiated';
  public const DONATION_SUCCEEDED = 'donation.succeeded';
  public const DONATION_SUCCEEDED_ADMIN = 'donation.succeeded.admin';
  public const DONATION_FAILED = 'donation.failed';

  public const COUNSELING_REQUEST_SUBMITTED = 'counseling.request.submitted';
  public const COUNSELING_PAYMENT_REQUIRED = 'counseling.payment.required';
  public const COUNSELING_PAYMENT_RECEIVED = 'counseling.payment.received';
  public const COUNSELING_COUNSELLOR_ASSIGNED = 'counseling.counsellor.assigned';
  public const COUNSELING_COUNSELLOR_ASSIGNED_CLIENT = 'counseling.counsellor.assigned.client';
  public const COUNSELING_APPOINTMENT_SCHEDULED = 'counseling.appointment.scheduled';
  public const COUNSELING_STATUS_UPDATED = 'counseling.status.updated';
  public const COUNSELING_CASE_CLOSED = 'counseling.case.closed';
  public const COUNSELING_MESSAGE_RECEIVED = 'counseling.message.received';

  public const MEMBERSHIP_APPLICATION_APPROVED = 'membership.application.approved';
  public const MEMBERSHIP_APPLICATION_REJECTED = 'membership.application.rejected';
  public const MEMBERSHIP_REQUEST_MORE_INFORMATION = 'membership.request.more_information';
  public const MEMBERSHIP_INTERVIEW_INVITATION = 'membership.interview.invitation';
  public const MEMBERSHIP_INTERVIEW_RESCHEDULED = 'membership.interview.rescheduled';
  public const MEMBERSHIP_INTERVIEW_CONFIRMED = 'membership.interview.confirmed';
  public const MEMBERSHIP_INTERVIEW_REMINDER = 'membership.interview.reminder';
  public const MEMBERSHIP_INTERVIEW_PASSED = 'membership.interview.passed';
  public const MEMBERSHIP_INTERVIEW_FAILED = 'membership.interview.failed';
  public const MEMBERSHIP_INTERVIEW_AWAITING_REVIEW = 'membership.interview.awaiting_review';
  public const MEMBERSHIP_INTERVIEW_CANCELLED = 'membership.interview.cancelled';
  public const MEMBERSHIP_ACCOUNT_CREATED = 'membership.account.created';
  public const MEMBERSHIP_ACCOUNT_UPGRADED = 'membership.account.upgraded';
  public const MEMBERSHIP_WELCOME = 'membership.welcome';
  public const MEMBERSHIP_MINISTRY_ONBOARDING = 'membership.ministry.onboarding';

  /**
   * @return list<array{event_key: string, section: string, audience: string, description: string}>
   */
  public static function catalog(): array
  {
    return [
      ['event_key' => self::FORM_CONTACT_SUBMITTED, 'section' => 'contact', 'audience' => 'applicant', 'description' => 'Contact form confirmation'],
      ['event_key' => self::FORM_CONTACT_SUBMITTED_ADMIN, 'section' => 'contact', 'audience' => 'admin', 'description' => 'Contact form admin alert'],
      ['event_key' => self::FORM_PRAYER_SUBMITTED, 'section' => 'prayer', 'audience' => 'applicant', 'description' => 'Prayer request confirmation'],
      ['event_key' => self::FORM_PRAYER_SUBMITTED_ADMIN, 'section' => 'prayer', 'audience' => 'admin', 'description' => 'Prayer request admin alert'],
      ['event_key' => self::FORM_COUNSELING_SUBMITTED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling form confirmation (fallback if no case)'],
      ['event_key' => self::FORM_COUNSELING_SUBMITTED_ADMIN, 'section' => 'counseling', 'audience' => 'admin', 'description' => 'Counselling request admin alert'],
      ['event_key' => self::FORM_MEMBERSHIP_SUBMITTED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership application confirmation'],
      ['event_key' => self::FORM_MEMBERSHIP_SUBMITTED_ADMIN, 'section' => 'membership', 'audience' => 'admin', 'description' => 'Membership application admin alert'],
      ['event_key' => self::FORM_PARTNERSHIP_SUBMITTED, 'section' => 'partnership', 'audience' => 'applicant', 'description' => 'Partnership application confirmation'],
      ['event_key' => self::FORM_PARTNERSHIP_SUBMITTED_ADMIN, 'section' => 'partnership', 'audience' => 'admin', 'description' => 'Partnership application admin alert'],
      ['event_key' => self::FORM_NEWSLETTER_SUBMITTED, 'section' => 'newsletter', 'audience' => 'applicant', 'description' => 'Newsletter subscription confirmation'],
      ['event_key' => self::FORM_NEWSLETTER_SUBMITTED_ADMIN, 'section' => 'newsletter', 'audience' => 'admin', 'description' => 'Newsletter subscription admin alert'],
      ['event_key' => self::FORM_DONATION_SUBMITTED, 'section' => 'donations', 'audience' => 'applicant', 'description' => 'Donation interest confirmation'],
      ['event_key' => self::FORM_DONATION_SUBMITTED_ADMIN, 'section' => 'donations', 'audience' => 'admin', 'description' => 'Donation interest admin alert'],
      ['event_key' => self::FORM_VOLUNTEER_SUBMITTED, 'section' => 'events', 'audience' => 'applicant', 'description' => 'Volunteer application confirmation'],
      ['event_key' => self::FORM_VOLUNTEER_SUBMITTED_ADMIN, 'section' => 'events', 'audience' => 'admin', 'description' => 'Volunteer application admin alert'],
      ['event_key' => self::FORM_TESTIMONY_SUBMITTED, 'section' => 'contact', 'audience' => 'applicant', 'description' => 'Testimony confirmation'],
      ['event_key' => self::FORM_TESTIMONY_SUBMITTED_ADMIN, 'section' => 'contact', 'audience' => 'admin', 'description' => 'Testimony admin alert'],
      ['event_key' => self::AUTH_LEARNER_REGISTERED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Learner account welcome'],
      ['event_key' => self::AUTH_PASSWORD_RESET, 'section' => 'learning', 'audience' => 'user', 'description' => 'Password reset'],
      ['event_key' => self::AUTH_EMAIL_VERIFICATION, 'section' => 'learning', 'audience' => 'user', 'description' => 'Email verification'],
      ['event_key' => self::FORM_BUSINESS_REVIEW_SUBMITTED, 'section' => 'contact', 'audience' => 'applicant', 'description' => 'Business review confirmation'],
      ['event_key' => self::FORM_BUSINESS_REVIEW_SUBMITTED_ADMIN, 'section' => 'contact', 'audience' => 'admin', 'description' => 'Business review admin alert'],
      ['event_key' => self::BUSINESS_REVIEW_STATUS_UPDATED, 'section' => 'contact', 'audience' => 'applicant', 'description' => 'Business review status update'],
      ['event_key' => self::LMS_ENROLLMENT_CREATED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Course enrollment'],
      ['event_key' => self::LMS_SCHOOL_ENROLLMENT_CREATED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'School enrollment recorded'],
      ['event_key' => self::LMS_SCHOOL_ENROLLMENT_ACTIVATED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'School enrollment activated'],
      ['event_key' => self::LMS_PAYMENT_CONFIRMED, 'section' => 'payments', 'audience' => 'learner', 'description' => 'Course payment confirmed'],
      ['event_key' => self::LMS_SCHOOL_PAYMENT_CONFIRMED, 'section' => 'payments', 'audience' => 'learner', 'description' => 'School payment confirmed'],
      ['event_key' => self::LMS_PAYMENT_OFFLINE_SUBMITTED, 'section' => 'payments', 'audience' => 'learner', 'description' => 'Offline payment submitted'],
      ['event_key' => self::LMS_PAYMENT_REJECTED, 'section' => 'payments', 'audience' => 'learner', 'description' => 'Payment rejected'],
      ['event_key' => self::LMS_PAYMENT_REFUNDED, 'section' => 'payments', 'audience' => 'learner', 'description' => 'Payment refunded'],
      ['event_key' => self::LMS_COURSE_COMPLETED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Course completed'],
      ['event_key' => self::LMS_MODULE_COMPLETED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Programme module completed'],
      ['event_key' => self::LMS_ASSIGNMENT_SUBMITTED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Assignment submitted'],
      ['event_key' => self::LMS_ASSIGNMENT_GRADED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Assignment graded'],
      ['event_key' => self::LMS_CERTIFICATE_ISSUED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Certificate issued'],
      ['event_key' => self::LMS_ASSESSMENT_SUBMITTED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Assessment submitted'],
      ['event_key' => self::LMS_ASSESSMENT_PASSED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Assessment passed'],
      ['event_key' => self::LMS_ASSESSMENT_FAILED, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Assessment failed'],
      ['event_key' => self::LMS_TRANSCRIPT_AVAILABLE, 'section' => 'learning', 'audience' => 'learner', 'description' => 'Transcript available'],
      ['event_key' => self::EVENT_REGISTRATION_CONFIRMED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event registration confirmation'],
      ['event_key' => self::EVENT_REGISTRATION_CONFIRMED_ADMIN, 'section' => 'events', 'audience' => 'admin', 'description' => 'Event registration admin alert'],
      ['event_key' => self::EVENT_REGISTRATION_CANCELLED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event registration cancelled'],
      ['event_key' => self::EVENT_UPDATED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event updated'],
      ['event_key' => self::EVENT_CANCELLED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event cancelled'],
      ['event_key' => self::EVENT_REMINDER, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event reminder'],
      ['event_key' => self::EVENT_ANNOUNCEMENT, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event announcement'],
      ['event_key' => self::EVENT_CERTIFICATE_ISSUED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event certificate issued'],
      ['event_key' => self::EVENT_CHECK_IN_REMINDER, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event check-in reminder'],
      ['event_key' => self::EVENT_VOLUNTEER_ASSIGNED, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event volunteer assigned'],
      ['event_key' => self::EVENT_SCHEDULE_CHANGE, 'section' => 'events', 'audience' => 'registrant', 'description' => 'Event schedule change'],
      ['event_key' => self::DONATION_INITIATED, 'section' => 'donations', 'audience' => 'donor', 'description' => 'Donation initiated'],
      ['event_key' => self::DONATION_SUCCEEDED, 'section' => 'donations', 'audience' => 'donor', 'description' => 'Donation succeeded'],
      ['event_key' => self::DONATION_SUCCEEDED_ADMIN, 'section' => 'donations', 'audience' => 'admin', 'description' => 'Donation admin alert'],
      ['event_key' => self::DONATION_FAILED, 'section' => 'donations', 'audience' => 'donor', 'description' => 'Donation failed'],
      ['event_key' => self::COUNSELING_REQUEST_SUBMITTED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling case submitted'],
      ['event_key' => self::COUNSELING_PAYMENT_REQUIRED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling payment required'],
      ['event_key' => self::COUNSELING_PAYMENT_RECEIVED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling payment received'],
      ['event_key' => self::COUNSELING_COUNSELLOR_ASSIGNED, 'section' => 'counseling', 'audience' => 'staff', 'description' => 'Counsellor assigned (staff)'],
      ['event_key' => self::COUNSELING_COUNSELLOR_ASSIGNED_CLIENT, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counsellor assigned (client)'],
      ['event_key' => self::COUNSELING_APPOINTMENT_SCHEDULED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Appointment scheduled'],
      ['event_key' => self::COUNSELING_STATUS_UPDATED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling status updated'],
      ['event_key' => self::COUNSELING_CASE_CLOSED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling case closed'],
      ['event_key' => self::COUNSELING_MESSAGE_RECEIVED, 'section' => 'counseling', 'audience' => 'applicant', 'description' => 'Counselling message received'],
      ['event_key' => self::MEMBERSHIP_APPLICATION_APPROVED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership application approved'],
      ['event_key' => self::MEMBERSHIP_APPLICATION_REJECTED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership application rejected'],
      ['event_key' => self::MEMBERSHIP_REQUEST_MORE_INFORMATION, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'More information requested'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_INVITATION, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview invitation'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_RESCHEDULED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview rescheduled'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_CONFIRMED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview confirmed'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_REMINDER, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview reminder'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_PASSED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview passed'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_FAILED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview failed'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_AWAITING_REVIEW, 'section' => 'membership', 'audience' => 'staff', 'description' => 'Interview awaiting admin review'],
      ['event_key' => self::MEMBERSHIP_INTERVIEW_CANCELLED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Membership interview cancelled'],
      ['event_key' => self::MEMBERSHIP_ACCOUNT_CREATED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Member account credentials'],
      ['event_key' => self::MEMBERSHIP_ACCOUNT_UPGRADED, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Existing account upgraded to member'],
      ['event_key' => self::MEMBERSHIP_WELCOME, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Member welcome'],
      ['event_key' => self::MEMBERSHIP_MINISTRY_ONBOARDING, 'section' => 'membership', 'audience' => 'applicant', 'description' => 'Ministry and country onboarding'],
    ];
  }

  /** @return list<string> */
  public static function all(): array
  {
    return array_column(self::catalog(), 'event_key');
  }

  public static function isKnown(string $eventKey): bool
  {
    return in_array($eventKey, self::all(), true);
  }

  public static function isAdminAlert(string $eventKey): bool
  {
    return str_ends_with($eventKey, '.admin') || $eventKey === self::MEMBERSHIP_INTERVIEW_AWAITING_REVIEW;
  }

  /**
   * Map legacy MemberNotificationQueue / MemberNotificationMail template keys to canonical event keys.
   */
  public static function fromLegacyTemplate(string $template): string
  {
    return match ($template) {
      'application_submitted' => self::FORM_MEMBERSHIP_SUBMITTED,
      'application_submitted_admin' => self::FORM_MEMBERSHIP_SUBMITTED_ADMIN,
      'application_approved' => self::MEMBERSHIP_APPLICATION_APPROVED,
      'application_rejected' => self::MEMBERSHIP_APPLICATION_REJECTED,
      'request_more_information' => self::MEMBERSHIP_REQUEST_MORE_INFORMATION,
      'interview_invitation', 'interview_scheduled' => self::MEMBERSHIP_INTERVIEW_INVITATION,
      'interview_rescheduled' => self::MEMBERSHIP_INTERVIEW_RESCHEDULED,
      'interview_confirmed' => self::MEMBERSHIP_INTERVIEW_CONFIRMED,
      'interview_reminder' => self::MEMBERSHIP_INTERVIEW_REMINDER,
      'interview_passed' => self::MEMBERSHIP_INTERVIEW_PASSED,
      'interview_failed' => self::MEMBERSHIP_INTERVIEW_FAILED,
      'interview_awaiting_review' => self::MEMBERSHIP_INTERVIEW_AWAITING_REVIEW,
      'interview_cancelled' => self::MEMBERSHIP_INTERVIEW_CANCELLED,
      'member_account_created' => self::MEMBERSHIP_ACCOUNT_CREATED,
      'member_account_upgraded' => self::MEMBERSHIP_ACCOUNT_UPGRADED,
      'member_welcome' => self::MEMBERSHIP_WELCOME,
      'ministry_country_onboarding' => self::MEMBERSHIP_MINISTRY_ONBOARDING,
      'counselling.request_submitted' => self::COUNSELING_REQUEST_SUBMITTED,
      'counselling.request_submitted_admin' => self::FORM_COUNSELING_SUBMITTED_ADMIN,
      'counselling.payment_required' => self::COUNSELING_PAYMENT_REQUIRED,
      'counselling.payment_received' => self::COUNSELING_PAYMENT_RECEIVED,
      'counselling.appointment_scheduled', 'counselling.reminder', 'counselling.rescheduled' => self::COUNSELING_APPOINTMENT_SCHEDULED,
      'counselling.cancelled', 'counselling.completed', 'counselling.case_accepted', 'counselling.case_rejected', 'counselling.more_info_requested', 'counselling.feedback_request' => self::COUNSELING_STATUS_UPDATED,
      'counselling.case_closed' => self::COUNSELING_CASE_CLOSED,
      'counselling.counsellor_assigned' => self::COUNSELING_COUNSELLOR_ASSIGNED,
      'counselling.counsellor_assigned_client' => self::COUNSELING_COUNSELLOR_ASSIGNED_CLIENT,
      'counselling.message_received' => self::COUNSELING_MESSAGE_RECEIVED,
      'event_certificate_issued' => self::EVENT_CERTIFICATE_ISSUED,
      'event_check_in_reminder' => self::EVENT_CHECK_IN_REMINDER,
      'event_volunteer_assigned' => self::EVENT_VOLUNTEER_ASSIGNED,
      'event_schedule_change', 'event_schedule_reminder' => self::EVENT_SCHEDULE_CHANGE,
      default => $template,
    };
  }

  public static function isValidFormat(string $eventKey): bool
  {
    return (bool) preg_match('/^[a-z0-9]+(?:[._-][a-z0-9_]+)+$/', $eventKey);
  }
}
