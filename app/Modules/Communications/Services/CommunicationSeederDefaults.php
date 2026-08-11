<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;

/** Default template bodies for system template reset. */
final class CommunicationSeederDefaults implements ServiceContract
{
  /** @return array<string, mixed>|null */
  public function templateDefaults(string $eventKey): ?array
  {
    $defaults = $this->allTemplates();

    return $defaults[$eventKey] ?? null;
  }

  /** @return array<string, array<string, mixed>> */
  public function allTemplates(): array
  {
    $vars = fn (array $keys): array => array_values($keys);

    return array_merge([
      'form.contact.submitted' => $this->tpl(
        'form-contact-submitted',
        'Contact form confirmation',
        'contact',
        'form.contact.submitted',
        'We received your message',
        '<p>Hello {{applicant_name}},</p><p>Thank you for contacting Marketplace Ministers. We received your message and will respond soon.</p>',
        $vars(['applicant_name', 'email', 'phone', 'site_name']),
        ['applicant_name' => 'Jane Doe', 'email' => 'jane@example.com'],
      ),
      'form.contact.submitted.admin' => $this->tpl(
        'form-contact-admin',
        'Contact form admin alert',
        'contact',
        'form.contact.submitted.admin',
        'New contact form submission',
        '<p>A new contact form was submitted by {{applicant_name}} ({{email}}).</p>',
        $vars(['applicant_name', 'email']),
        ['applicant_name' => 'Jane Doe', 'email' => 'jane@example.com'],
      ),
      'form.prayer.submitted' => $this->tpl(
        'form-prayer-submitted',
        'Prayer request confirmation',
        'prayer',
        'form.prayer.submitted',
        'We received your prayer request',
        '<p>Hello {{applicant_name}},</p><p>Your prayer request has been received. Our intercessors will stand with you in faith.</p>',
        $vars(['applicant_name', 'email']),
        ['applicant_name' => 'John Smith'],
      ),
      'form.prayer.submitted.admin' => $this->tpl(
        'form-prayer-admin',
        'Prayer request admin alert',
        'prayer',
        'form.prayer.submitted.admin',
        'New prayer request',
        '<p>New prayer request from {{applicant_name}} ({{email}}).</p>',
        $vars(['applicant_name', 'email']),
        ['applicant_name' => 'John Smith', 'email' => 'john@example.com'],
      ),
      'form.counseling.submitted' => $this->tpl(
        'form-counseling-submitted',
        'Counseling request confirmation',
        'counseling',
        'form.counseling.submitted',
        'We received your counselling request',
        '<p>Hello {{applicant_name}},</p><p>Your counselling request has been received. A member of our team will follow up with you.</p>',
        $vars(['applicant_name', 'email', 'case_number']),
        ['applicant_name' => 'Sarah Lee', 'case_number' => 'CN-1001'],
      ),
      'form.counseling.submitted.admin' => $this->tpl(
        'form-counseling-admin',
        'Counseling request admin alert',
        'counseling',
        'form.counseling.submitted.admin',
        'New counselling request',
        '<p>New counselling request from {{applicant_name}} ({{email}}).</p>',
        $vars(['applicant_name', 'email', 'case_number']),
        ['applicant_name' => 'Sarah Lee', 'case_number' => 'CN-1001'],
      ),
      'lms.enrollment.created' => $this->tpl(
        'lms-enrollment-created',
        'Course enrollment confirmation',
        'learning',
        'lms.enrollment.created',
        'You are enrolled in {{course_name}}',
        '<p>Hello {{member_name}},</p><p>You have been enrolled in <strong>{{course_name}}</strong>.</p><p><a href="{{course_url}}">Open course</a></p>',
        $vars(['member_name', 'course_name', 'course_url', 'dashboard_url']),
        ['member_name' => 'Learner', 'course_name' => 'Introduction to Ministry', 'course_url' => 'https://example.com/courses/intro'],
      ),
      'lms.school.enrollment.created' => $this->tpl(
        'lms-school-enrollment-created',
        'School enrollment confirmation',
        'learning',
        'lms.school.enrollment.created',
        'School enrollment: {{school_name}}',
        '<p>Hello {{member_name}},</p><p>Your enrollment in <strong>{{school_name}}</strong> has been recorded.</p>',
        $vars(['member_name', 'school_name', 'dashboard_url']),
        ['member_name' => 'Learner', 'school_name' => 'School of Pastors'],
      ),
      'lms.payment.confirmed' => $this->tpl(
        'lms-payment-confirmed',
        'Course payment confirmed',
        'payments',
        'lms.payment.confirmed',
        'Payment confirmed for {{course_name}}',
        '<p>Hello {{member_name}},</p><p>Your payment of {{amount}} {{currency}} for <strong>{{course_name}}</strong> has been confirmed.</p><p>Reference: {{payment_reference}}</p>',
        $vars(['member_name', 'course_name', 'amount', 'currency', 'payment_reference']),
        ['member_name' => 'Learner', 'course_name' => 'Leadership 101', 'amount' => '75.00', 'currency' => 'USD', 'payment_reference' => 'PAY-123'],
      ),
      'lms.school.payment.confirmed' => $this->tpl(
        'lms-school-payment-confirmed',
        'School payment confirmed',
        'payments',
        'lms.school.payment.confirmed',
        'School payment confirmed — {{school_name}}',
        '<p>Hello {{member_name}},</p><p>Your payment of {{amount}} {{currency}} for <strong>{{school_name}}</strong> has been confirmed. Your enrollment is now active.</p><p>Reference: {{payment_reference}}</p>',
        $vars(['member_name', 'school_name', 'amount', 'currency', 'payment_reference']),
        ['member_name' => 'Learner', 'school_name' => 'School of Pastors', 'amount' => '150.00', 'currency' => 'USD', 'payment_reference' => 'PAY-456'],
      ),
      'lms.payment.offline.submitted' => $this->tpl(
        'lms-payment-offline-submitted',
        'Offline payment submitted',
        'payments',
        'lms.payment.offline.submitted',
        'Offline payment received — pending approval',
        '<p>Hello {{member_name}},</p><p>We received your offline payment submission for {{course_name}}. An administrator will review and confirm access shortly.</p>',
        $vars(['member_name', 'course_name', 'payment_reference']),
        ['member_name' => 'Learner', 'course_name' => 'Leadership 101'],
      ),
      'lms.course.completed' => $this->tpl(
        'lms-course-completed',
        'Course completed',
        'learning',
        'lms.course.completed',
        'Congratulations — {{course_name}} completed',
        '<p>Hello {{member_name}},</p><p>Congratulations on completing <strong>{{course_name}}</strong>.</p>',
        $vars(['member_name', 'course_name']),
        ['member_name' => 'Learner', 'course_name' => 'Introduction to Ministry'],
      ),
      'lms.assignment.graded' => $this->tpl(
        'lms-assignment-graded',
        'Assignment graded',
        'learning',
        'lms.assignment.graded',
        'Assignment graded: {{assignment_title}}',
        '<p>Hello {{member_name}},</p><p>Your assignment <strong>{{assignment_title}}</strong> has been graded.</p>',
        $vars(['member_name', 'assignment_title']),
        ['member_name' => 'Learner', 'assignment_title' => 'Reflection Paper'],
      ),
      'lms.certificate.issued' => $this->tpl(
        'lms-certificate-issued',
        'Certificate issued',
        'learning',
        'lms.certificate.issued',
        'Your certificate for {{course_name}} is ready',
        '<p>Hello {{member_name}},</p><p>Your certificate for <strong>{{course_name}}</strong> is available in your learner dashboard.</p>',
        $vars(['member_name', 'course_name', 'certificate_name']),
        ['member_name' => 'Learner', 'course_name' => 'Introduction to Ministry'],
      ),
      'event.registration.confirmed' => $this->tpl(
        'event-registration-confirmed',
        'Event registration confirmation',
        'events',
        'event.registration.confirmed',
        'Registration confirmed — {{event_name}}',
        '<p>Hello {{applicant_name}},</p><p>You are registered for <strong>{{event_name}}</strong> on {{event_date}} at {{event_location}}.</p>',
        $vars(['applicant_name', 'event_name', 'event_date', 'event_time', 'event_location', 'event_url']),
        ['applicant_name' => 'Guest', 'event_name' => 'Annual Conference', 'event_date' => 'Aug 15, 2026', 'event_location' => 'Lagos'],
      ),
      'event.registration.confirmed.admin' => $this->tpl(
        'event-registration-admin',
        'Event registration admin alert',
        'events',
        'event.registration.confirmed.admin',
        'New event registration — {{event_name}}',
        '<p>{{applicant_name}} ({{email}}) registered for <strong>{{event_name}}</strong> on {{event_date}}.</p>',
        $vars(['applicant_name', 'email', 'event_name', 'event_date']),
        ['applicant_name' => 'Guest', 'event_name' => 'Annual Conference', 'event_date' => 'Aug 15, 2026'],
      ),
      'form.membership.submitted' => $this->tpl(
        'form-membership-submitted',
        'Membership application confirmation',
        'membership',
        'form.membership.submitted',
        'We received your membership application',
        '<p>Hello {{applicant_name}},</p><p>Thank you for applying to join Marketplace Ministers. Our team will review your application.</p>',
        $vars(['applicant_name', 'email', 'application_number']),
        ['applicant_name' => 'Applicant Name', 'application_number' => 'APP-1001'],
      ),
      'form.membership.submitted.admin' => $this->tpl(
        'form-membership-admin',
        'Membership application admin alert',
        'membership',
        'form.membership.submitted.admin',
        'New membership application',
        '<p>New membership application from {{applicant_name}} ({{email}}). Application #{{application_number}}.</p>',
        $vars(['applicant_name', 'email', 'application_number']),
        ['applicant_name' => 'Applicant Name', 'application_number' => 'APP-1001'],
      ),
      'lms.school.enrollment.activated' => $this->tpl(
        'lms-school-enrollment-activated',
        'School enrollment activated',
        'learning',
        'lms.school.enrollment.activated',
        'Your school enrollment is active — {{school_name}}',
        '<p>Hello {{member_name}},</p><p>Your enrollment in <strong>{{school_name}}</strong> is now active. You may begin learning.</p>',
        $vars(['member_name', 'school_name', 'dashboard_url']),
        ['member_name' => 'Learner', 'school_name' => 'School of Pastors'],
      ),
    ], $this->extendedTemplates());
  }

  /** @return array<string, array<string, mixed>> */
  private function extendedTemplates(): array
  {
    $vars = fn (array $keys): array => array_values($keys);
    $v = $vars;

    return [
      'auth.learner.registered' => $this->tpl('auth-learner-registered', 'Learner registration confirmation', 'learning', 'auth.learner.registered', 'Welcome to Marketplace Ministers learning', '<p>Hello {{learner_name}},</p><p>Your learner account has been created. Sign in to begin learning.</p><p><a href="{{login_url}}">Sign in</a></p>', $v(['learner_name', 'email', 'login_url', 'dashboard_url']), ['learner_name' => 'Learner']),
      'form.partnership.submitted' => $this->tpl('form-partnership-submitted', 'Partnership inquiry confirmation', 'partnership', 'form.partnership.submitted', 'We received your partnership inquiry', '<p>Hello {{applicant_name}},</p><p>Thank you for your partnership inquiry. Our team will follow up soon.</p>', $v(['applicant_name', 'email']), ['applicant_name' => 'Partner']),
      'form.partnership.submitted.admin' => $this->tpl('form-partnership-admin', 'Partnership inquiry admin alert', 'partnership', 'form.partnership.submitted.admin', 'New partnership inquiry', '<p>New partnership inquiry from {{applicant_name}} ({{email}}).</p>', $v(['applicant_name', 'email']), ['applicant_name' => 'Partner']),
      'form.newsletter.submitted' => $this->tpl('form-newsletter-submitted', 'Newsletter subscription confirmation', 'newsletter', 'form.newsletter.submitted', 'You are subscribed', '<p>Hello {{applicant_name}},</p><p>Thank you for subscribing to Marketplace Ministers updates.</p>', $v(['applicant_name', 'email']), ['applicant_name' => 'Subscriber']),
      'form.newsletter.submitted.admin' => $this->tpl('form-newsletter-admin', 'Newsletter subscription admin alert', 'newsletter', 'form.newsletter.submitted.admin', 'New newsletter subscription', '<p>{{applicant_name}} ({{email}}) subscribed to the newsletter.</p>', $v(['applicant_name', 'email']), []),
      'form.donation.submitted' => $this->tpl('form-donation-submitted', 'Donation interest confirmation', 'donations', 'form.donation.submitted', 'We received your donation request', '<p>Hello {{applicant_name}},</p><p>Thank you. We received your donation details and will confirm once processed.</p>', $v(['applicant_name', 'email', 'amount', 'currency']), ['applicant_name' => 'Donor']),
      'form.donation.submitted.admin' => $this->tpl('form-donation-admin', 'Donation interest admin alert', 'donations', 'form.donation.submitted.admin', 'New donation submission', '<p>Donation from {{applicant_name}} ({{email}}) — {{amount}} {{currency}}.</p>', $v(['applicant_name', 'email', 'amount', 'currency']), []),
      'form.volunteer.submitted' => $this->tpl('form-volunteer-submitted', 'Volunteer application confirmation', 'events', 'form.volunteer.submitted', 'We received your volunteer application', '<p>Hello {{applicant_name}},</p><p>Thank you for offering to serve. Our events team will follow up.</p>', $v(['applicant_name', 'email']), ['applicant_name' => 'Volunteer']),
      'form.volunteer.submitted.admin' => $this->tpl('form-volunteer-admin', 'Volunteer application admin alert', 'events', 'form.volunteer.submitted.admin', 'New volunteer application', '<p>Volunteer application from {{applicant_name}} ({{email}}).</p>', $v(['applicant_name', 'email']), []),
      'form.testimony.submitted' => $this->tpl('form-testimony-submitted', 'Testimony submission confirmation', 'contact', 'form.testimony.submitted', 'We received your testimony', '<p>Hello {{applicant_name}},</p><p>Thank you for sharing your testimony.</p>', $v(['applicant_name', 'email']), ['applicant_name' => 'Member']),
      'form.testimony.submitted.admin' => $this->tpl('form-testimony-admin', 'Testimony submission admin alert', 'contact', 'form.testimony.submitted.admin', 'New testimony submission', '<p>New testimony from {{applicant_name}} ({{email}}).</p>', $v(['applicant_name', 'email']), []),
      'donation.initiated' => $this->tpl('donation-initiated', 'Donation initiated', 'donations', 'donation.initiated', 'Donation initiated — {{payment_reference}}', '<p>Hello {{applicant_name}},</p><p>We received your donation of {{amount}} {{currency}}. Complete payment to finalize your gift.</p>', $v(['applicant_name', 'amount', 'currency', 'payment_reference', 'fund_name']), ['amount' => '100.00', 'currency' => 'USD', 'payment_reference' => 'DN-123']),
      'donation.succeeded' => $this->tpl('donation-succeeded', 'Donation successful', 'donations', 'donation.succeeded', 'Thank you for your donation', '<p>Hello {{applicant_name}},</p><p>Your donation of {{amount}} {{currency}} has been received. Reference: {{payment_reference}}.</p>', $v(['applicant_name', 'amount', 'currency', 'payment_reference', 'fund_name']), ['amount' => '100.00', 'currency' => 'USD']),
      'donation.succeeded.admin' => $this->tpl('donation-succeeded-admin', 'Donation admin alert', 'donations', 'donation.succeeded.admin', 'Donation received — {{payment_reference}}', '<p>Donation of {{amount}} {{currency}} from {{applicant_name}} ({{email}}).</p>', $v(['applicant_name', 'email', 'amount', 'currency', 'payment_reference']), []),
      'donation.failed' => $this->tpl('donation-failed', 'Donation failed', 'donations', 'donation.failed', 'Donation could not be completed', '<p>Hello {{applicant_name}},</p><p>Your donation could not be completed. {{reason}}</p>', $v(['applicant_name', 'reason', 'payment_reference']), []),
      'lms.assessment.submitted' => $this->tpl('lms-assessment-submitted', 'Assessment submitted', 'learning', 'lms.assessment.submitted', 'Assessment submitted — {{assessment_name}}', '<p>Hello {{learner_name}},</p><p>Your submission for <strong>{{assessment_name}}</strong> in {{course_name}} has been received.</p>', $v(['learner_name', 'assessment_name', 'course_name']), ['learner_name' => 'Learner', 'assessment_name' => 'Midterm']),
      'lms.assessment.passed' => $this->tpl('lms-assessment-passed', 'Assessment passed', 'learning', 'lms.assessment.passed', 'You passed {{assessment_name}}', '<p>Hello {{learner_name}},</p><p>Congratulations — you passed <strong>{{assessment_name}}</strong> with {{percentage}}.</p>', $v(['learner_name', 'assessment_name', 'percentage', 'pass_status', 'result_url']), ['percentage' => '85%']),
      'lms.assessment.failed' => $this->tpl('lms-assessment-failed', 'Assessment result', 'learning', 'lms.assessment.failed', 'Assessment result — {{assessment_name}}', '<p>Hello {{learner_name}},</p><p>Your result for <strong>{{assessment_name}}</strong> is available: {{pass_status}} ({{percentage}}).</p>', $v(['learner_name', 'assessment_name', 'percentage', 'pass_status', 'result_url']), []),
      'lms.module.completed' => $this->tpl('lms-module-completed', 'Module completed', 'learning', 'lms.module.completed', 'Module completed — {{module_name}}', '<p>Hello {{learner_name}},</p><p>Congratulations on completing the programme module <strong>{{module_name}}</strong>.</p>', $v(['learner_name', 'module_name', 'school_name', 'dashboard_url']), ['module_name' => 'Foundation Module']),
      'lms.transcript.available' => $this->tpl('lms-transcript-available', 'Transcript available', 'learning', 'lms.transcript.available', 'Your learning transcript is available', '<p>Hello {{learner_name}},</p><p>Your transcript is ready to view securely in your learner dashboard.</p><p><a href="{{result_url}}">View transcript</a></p>', $v(['learner_name', 'result_url', 'dashboard_url']), []),
      'lms.payment.rejected' => $this->tpl('lms-payment-rejected', 'Payment rejected', 'payments', 'lms.payment.rejected', 'Payment not confirmed — {{course_name}}', '<p>Hello {{learner_name}},</p><p>Your payment for {{course_name}} could not be confirmed. {{reason}}</p>', $v(['learner_name', 'course_name', 'payment_reference', 'reason']), []),
      'lms.payment.refunded' => $this->tpl('lms-payment-refunded', 'Payment refunded', 'payments', 'lms.payment.refunded', 'Refund processed — {{course_name}}', '<p>Hello {{learner_name}},</p><p>A refund of {{amount}} {{currency}} for {{course_name}} has been processed.</p>', $v(['learner_name', 'course_name', 'amount', 'currency', 'payment_reference']), []),
      'counseling.request.submitted' => $this->tpl('counseling-request-submitted', 'Counseling request confirmation', 'counseling', 'counseling.request.submitted', 'We received your counselling request', '<p>Hello {{applicant_name}},</p><p>Your counselling request ({{case_number}}) has been received.</p>', $v(['applicant_name', 'case_number', 'email']), ['case_number' => 'CN-1001']),
      'counseling.counsellor.assigned' => $this->tpl('counseling-counsellor-assigned', 'Counsellor assigned (staff)', 'counseling', 'counseling.counsellor.assigned', 'New counselling assignment — {{case_number}}', '<p>A counselling case ({{case_number}}) for {{client_name}} has been assigned to you.</p>', $v(['case_number', 'client_name', 'service_title']), []),
      'counseling.counsellor.assigned.client' => $this->tpl('counseling-client-counsellor-assigned', 'Counsellor assigned (client)', 'counseling', 'counseling.counsellor.assigned.client', 'Counsellor assigned to your case', '<p>Hello {{applicant_name}},</p><p>A counsellor has been assigned to case {{case_number}}.</p>', $v(['applicant_name', 'case_number', 'counsellor_name']), []),
      'counseling.appointment.scheduled' => $this->tpl('counseling-appointment-scheduled', 'Appointment scheduled', 'counseling', 'counseling.appointment.scheduled', 'Counselling appointment scheduled', '<p>Hello {{applicant_name}},</p><p>Your counselling appointment for case {{case_number}} has been scheduled.</p>', $v(['applicant_name', 'case_number']), []),
      'counseling.status.updated' => $this->tpl('counseling-status-updated', 'Counseling status update', 'counseling', 'counseling.status.updated', 'Counselling case update — {{case_number}}', '<p>Hello {{applicant_name}},</p><p>Your counselling case {{case_number}} has been updated.</p>', $v(['applicant_name', 'case_number', 'application_status']), []),
      'counseling.case.closed' => $this->tpl('counseling-case-closed', 'Case closed', 'counseling', 'counseling.case.closed', 'Counselling case closed — {{case_number}}', '<p>Hello {{applicant_name}},</p><p>Your counselling case {{case_number}} has been closed.</p>', $v(['applicant_name', 'case_number']), []),
      'counseling.message.received' => $this->tpl('counseling-message-received', 'New counselling message', 'counseling', 'counseling.message.received', 'New message on case {{case_number}}', '<p>You have a new counselling message regarding case {{case_number}}.</p>', $v(['case_number']), []),
      'event.registration.cancelled' => $this->tpl('event-registration-cancelled', 'Registration cancelled', 'events', 'event.registration.cancelled', 'Registration cancelled — {{event_name}}', '<p>Hello {{applicant_name}},</p><p>Your registration for <strong>{{event_name}}</strong> has been cancelled.</p>', $v(['applicant_name', 'event_name', 'event_date']), []),
      'event.updated' => $this->tpl('event-updated', 'Event updated', 'events', 'event.updated', 'Event update — {{event_name}}', '<p>Hello {{applicant_name}},</p><p><strong>{{event_name}}</strong> has been updated. {{reason}}</p>', $v(['applicant_name', 'event_name', 'event_date', 'event_location', 'reason']), []),
      'event.cancelled' => $this->tpl('event-cancelled', 'Event cancelled', 'events', 'event.cancelled', 'Event cancelled — {{event_name}}', '<p>Hello {{applicant_name}},</p><p>We regret to inform you that <strong>{{event_name}}</strong> has been cancelled.</p>', $v(['applicant_name', 'event_name', 'reason']), []),
      'event.reminder' => $this->tpl('event-reminder', 'Event reminder', 'events', 'event.reminder', 'Reminder — {{event_name}} on {{event_date}}', '<p>Hello {{applicant_name}},</p><p>This is a reminder that <strong>{{event_name}}</strong> is on {{event_date}} at {{event_location}}.</p>', $v(['applicant_name', 'event_name', 'event_date', 'event_time', 'event_location', 'event_url']), []),
    ];
  }

  /**
   * @param  list<string>  $availableVariables
   * @param  array<string, string>  $sampleVariables
   * @return array<string, mixed>
   */
  private function tpl(
    string $slug,
    string $name,
    string $section,
    string $eventKey,
    string $subject,
    string $htmlBody,
    array $availableVariables,
    array $sampleVariables,
  ): array {
    return [
      'slug' => $slug,
      'name' => $name,
      'section' => $section,
      'event_key' => $eventKey,
      'description' => $name,
      'subject' => $subject,
      'html_body' => $htmlBody,
      'text_body' => strip_tags(str_replace(['</p>', '<br>', '<br/>'], ["\n\n", "\n", "\n"], $htmlBody)),
      'available_variables' => $availableVariables,
      'sample_variables' => $sampleVariables,
      'is_active' => true,
      'is_system' => true,
    ];
  }
}
