<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class MemberNotificationMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  /**
   * @param  array<string, mixed>  $payload
   */
  public function __construct(
    public readonly string $template,
    public readonly array $payload,
    public readonly string $memberName,
  ) {}

  public function envelope(): Envelope
  {
    $subject = match ($this->template) {
      'application_submitted' => 'We received your membership application',
      'application_submitted_admin' => 'New membership application submitted',
      'interview_invitation', 'interview_scheduled' => 'Your membership interview invitation',
      'interview_rescheduled' => 'Your membership interview was rescheduled',
      'interview_confirmed' => 'Interview attendance confirmed',
      'interview_reminder' => 'Reminder: upcoming membership interview',
      'interview_passed' => 'You passed your membership interview',
      'interview_failed' => 'Membership interview outcome',
      'interview_awaiting_review' => 'Interview awaiting review',
      'interview_cancelled' => 'Membership interview cancelled',
      'application_approved' => 'Membership application approved',
      'member_account_created' => 'Your Marketplace Ministers login credentials',
      'member_account_upgraded' => 'Your account is now a Member Workspace login',
      'member_welcome' => 'Welcome to Marketplace Ministers',
      'ministry_country_onboarding' => 'Your ministry & country onboarding links',
      'lms.certificate.issued' => 'Your course certificate is ready',
      'lms.enrollment.created' => 'You are enrolled in a course',
      'lms.course.completed' => 'Congratulations — course completed',
      'lms.assignment.submitted' => 'Assignment submitted',
      'lms.assignment.graded' => 'Assignment graded',
      'lms.assessment.result' => 'Assessment result',
      'event_certificate_issued' => 'Your event certificate is ready',
      'counselling.request_submitted' => 'We received your counselling request',
      'counselling.payment_required' => 'Payment required for your counselling session',
      'counselling.payment_received' => 'Counselling payment received',
      'counselling.appointment_scheduled' => 'Your counselling appointment is scheduled',
      'counselling.reminder' => 'Reminder: upcoming counselling appointment',
      'counselling.rescheduled' => 'Your counselling appointment was rescheduled',
      'counselling.cancelled' => 'Counselling case cancelled',
      'counselling.completed' => 'Your counselling case is complete',
      'counselling.feedback_request' => 'Share feedback on your counselling session',
      'counselling.request_submitted_admin' => 'New counselling request submitted',
      'counselling.case_accepted' => 'Your counselling case is under review',
      'counselling.case_rejected' => 'Counselling case outcome',
      'counselling.more_info_requested' => 'More information needed for your counselling case',
      'counselling.case_closed' => 'Your counselling case is closed',
      'counselling.counsellor_assigned' => 'New counselling case assigned to you',
      'counselling.counsellor_assigned_client' => 'A counsellor was assigned to your case',
      'counselling.message_received' => 'New counselling message',
      default => 'Marketplace Ministers notification',
    };

    return new Envelope(subject: $subject);
  }

  public function content(): Content
  {
    return new Content(
      htmlString: $this->buildHtml(),
    );
  }

  private function buildHtml(): string
  {
    $name = e($this->memberName);
    $lines = [
      '<div style="font-family:Georgia,serif;max-width:640px;margin:0 auto;padding:24px;color:#1c1917;line-height:1.6">',
      '<p style="letter-spacing:.18em;text-transform:uppercase;font-size:11px;color:#a16207">Marketplace Ministers</p>',
      "<p>Hello {$name},</p>",
    ];

    $lines = array_merge($lines, match ($this->template) {
      'application_submitted' => $this->applicationSubmittedBody(),
      'application_submitted_admin' => $this->adminApplicationBody(),
      'interview_invitation', 'interview_scheduled' => $this->interviewInvitationBody(),
      'interview_rescheduled' => $this->interviewRescheduleBody(),
      'member_account_created' => $this->credentialsBody(),
      'member_account_upgraded' => $this->upgradedAccountBody(),
      'ministry_country_onboarding' => $this->onboardingLinksBody(),
      'application_approved' => [
        '<p>Congratulations — your membership application has been <strong>approved</strong>.</p>',
        '<p>Check your email for login credentials and onboarding next steps.</p>',
      ],
      'interview_passed' => [
        '<p>Great news — you <strong>passed</strong> your membership interview.</p>',
        '<p>Automatic onboarding has begun. Watch for your login credentials and ministry welcome details.</p>',
      ],
      'interview_failed' => [
        '<p>Thank you for attending your membership interview.</p>',
        '<p>The membership team will follow up regarding next steps.</p>',
      ],
      'interview_awaiting_review' => [
        '<p><strong>This interview is awaiting review.</strong></p>',
        '<p>Please record the interview outcome (Pass / Fail / Reschedule / Reject) in the admin workspace.</p>',
      ],
      'member_welcome' => [
        '<p>Welcome to the Marketplace Ministers tribe.</p>',
        '<p>Your Member Portal and Learning Portal are ready.</p>',
      ],
      'counselling.request_submitted' => $this->counsellingRequestSubmittedBody(),
      'counselling.payment_required' => $this->counsellingPaymentRequiredBody(),
      'counselling.payment_received' => $this->counsellingPaymentReceivedBody(),
      'counselling.appointment_scheduled' => $this->counsellingAppointmentBody('scheduled'),
      'counselling.reminder' => $this->counsellingAppointmentBody('reminder'),
      'counselling.rescheduled' => $this->counsellingAppointmentBody('rescheduled'),
      'counselling.cancelled' => $this->counsellingCancelledBody(),
      'counselling.completed' => $this->counsellingCompletedBody(),
      'counselling.feedback_request' => $this->counsellingFeedbackBody(),
      default => [
        '<p>Notification: <strong>'.e($this->template).'</strong></p>',
        $this->payload['reason'] ?? null ? '<p>'.e((string) $this->payload['reason']).'</p>' : '',
      ],
    });

    if (
      ($this->payload['login_url'] ?? null) !== null
      && ! in_array($this->template, ['member_account_created', 'member_account_upgraded'], true)
    ) {
      $lines[] = '<p><a href="'.e((string) $this->payload['login_url']).'">Open login</a></p>';
    }

    $lines[] = '<p style="margin-top:32px;color:#78716c;font-size:13px">Marketplace Ministers · Support: '
      .e((string) ($this->payload['support_contact'] ?? config('mail.from.address'))).'</p>';
    $lines[] = '</div>';

    return implode("\n", array_filter($lines, fn ($line) => $line !== '' && $line !== null));
  }

  /**
   * @return list<string>
   */
  private function applicationSubmittedBody(): array
  {
    return [
      '<p>We received your membership application'
        .(isset($this->payload['application_number']) ? ' (<strong>'.e((string) $this->payload['application_number']).'</strong>)' : '')
        .'.</p>',
      '<p>Our membership team will review your application and contact you about the next step.</p>',
      isset($this->payload['status_url'])
        ? '<p><a href="'.e((string) $this->payload['status_url']).'">Track your application status</a></p>'
        : '',
    ];
  }

  /**
   * @return list<string>
   */
  private function adminApplicationBody(): array
  {
    return [
      '<p>A new membership application was submitted'
        .(isset($this->payload['applicant_name']) ? ' by <strong>'.e((string) $this->payload['applicant_name']).'</strong>' : '')
        .'.</p>',
      isset($this->payload['application_number'])
        ? '<p>Application number: <code>'.e((string) $this->payload['application_number']).'</code></p>'
        : '',
      isset($this->payload['admin_url'])
        ? '<p><a href="'.e((string) $this->payload['admin_url']).'">Open in Admin Portal</a></p>'
        : '',
    ];
  }

  /**
   * @return list<string>
   */
  private function interviewInvitationBody(): array
  {
    $type = e((string) ($this->payload['interview_type'] ?? 'online'));
    $date = e((string) ($this->payload['scheduled_date'] ?? ''));
    $time = e((string) ($this->payload['scheduled_time'] ?? ''));
    $tz = e((string) ($this->payload['timezone'] ?? ''));

    $lines = [
      '<p>You are invited to a <strong>'.($type === 'physical' ? 'in-person' : 'virtual').'</strong> membership interview.</p>',
      "<p><strong>Date:</strong> {$date}<br><strong>Time:</strong> {$time}".($tz !== '' ? " ({$tz})" : '').'</p>',
    ];

    if (! empty($this->payload['meeting_link'])) {
      $lines[] = '<p><strong>Meeting link:</strong> <a href="'.e((string) $this->payload['meeting_link']).'">Join meeting</a></p>';
    }
    if (! empty($this->payload['meeting_platform'])) {
      $lines[] = '<p><strong>Platform:</strong> '.e((string) $this->payload['meeting_platform']).'</p>';
    }
    if (! empty($this->payload['meeting_password'])) {
      $lines[] = '<p><strong>Meeting password:</strong> <code>'.e((string) $this->payload['meeting_password']).'</code></p>';
    }
    if (! empty($this->payload['venue']) || ! empty($this->payload['physical_location'])) {
      $lines[] = '<p><strong>Venue:</strong> '.e((string) ($this->payload['venue'] ?? $this->payload['physical_location'])).'</p>';
    }
    if (! empty($this->payload['instructions'])) {
      $lines[] = '<p><strong>Instructions:</strong><br>'.nl2br(e((string) $this->payload['instructions'])).'</p>';
    }
    if (! empty($this->payload['confirmation_url'])) {
      $lines[] = '<p><a style="display:inline-block;padding:12px 18px;background:#a16207;color:#fff;text-decoration:none;border-radius:999px" href="'
        .e((string) $this->payload['confirmation_url']).'">Confirm attendance</a></p>';
    }
    if (! empty($this->payload['ical_url'])) {
      $lines[] = '<p><a href="'.e((string) $this->payload['ical_url']).'">Add to calendar (.ics)</a></p>';
    }

    return $lines;
  }

  /**
   * @return list<string>
   */
  private function interviewRescheduleBody(): array
  {
    return array_merge(
      ['<p>Your membership interview has been <strong>rescheduled</strong>.</p>'],
      $this->interviewInvitationBody(),
    );
  }

  /**
   * @return list<string>
   */
  private function credentialsBody(): array
  {
    return [
      '<p><strong>Congratulations!</strong> Your membership is approved.</p>',
      '<p>Username: <code>'.e((string) ($this->payload['username'] ?? '')).'</code></p>',
      '<p>Temporary password: <code>'.e((string) ($this->payload['temporary_password'] ?? '')).'</code></p>',
      '<p>Please sign in and change your password immediately.</p>',
      ! empty($this->payload['login_url'])
        ? '<p><a href="'.e((string) $this->payload['login_url']).'">Member Portal login</a></p>'
        : '',
      ! empty($this->payload['learn_login_url'])
        ? '<p><a href="'.e((string) $this->payload['learn_login_url']).'">Learning Portal login</a></p>'
        : '',
    ];
  }

  /**
   * @return list<string>
   */
  private function upgradedAccountBody(): array
  {
    return [
      '<p><strong>Congratulations!</strong> Your membership is approved.</p>',
      '<p>Your existing visitor account has been upgraded to <strong>Member Workspace</strong> access.</p>',
      '<p>Username: <code>'.e((string) ($this->payload['username'] ?? '')).'</code></p>',
      '<p>Your password is unchanged — sign in with the same credentials you already use.</p>',
      ! empty($this->payload['login_url'])
        ? '<p><a href="'.e((string) $this->payload['login_url']).'">Open Member Workspace</a></p>'
        : '',
      '<p>Your courses, certificates, transcript, prayer, counselling, and notifications remain on this same account.</p>',
    ];
  }

  /**
   * @return list<string>
   */
  private function onboardingLinksBody(): array
  {
    $lines = [
      '<p>Welcome into your ministry and country community channels.</p>',
    ];
    if (! empty($this->payload['ministry_name'])) {
      $lines[] = '<p><strong>Ministry:</strong> '.e((string) $this->payload['ministry_name']).'</p>';
    }
    if (! empty($this->payload['whatsapp_link'])) {
      $lines[] = '<p><a href="'.e((string) $this->payload['whatsapp_link']).'">Join WhatsApp group</a></p>';
    }
    if (! empty($this->payload['telegram_link'])) {
      $lines[] = '<p><a href="'.e((string) $this->payload['telegram_link']).'">Join Telegram</a></p>';
    }
    if (! empty($this->payload['signal_link'])) {
      $lines[] = '<p><a href="'.e((string) $this->payload['signal_link']).'">Join Signal</a></p>';
    }
    if (! empty($this->payload['country_name'])) {
      $lines[] = '<p><strong>Country:</strong> '.e((string) $this->payload['country_name']).'</p>';
    }
    if (! empty($this->payload['country_whatsapp'])) {
      $lines[] = '<p><a href="'.e((string) $this->payload['country_whatsapp']).'">Country WhatsApp group</a></p>';
    }
    if (! empty($this->payload['regional_group'])) {
      $lines[] = '<p>Regional group: '.e((string) $this->payload['regional_group']).'</p>';
    }

    return $lines;
  }

  /**
   * @return list<string>
   */
  private function counsellingRequestSubmittedBody(): array
  {
    return [
      '<p>We received your counselling request'
        .(isset($this->payload['case_number']) ? ' (<strong>'.e((string) $this->payload['case_number']).'</strong>)' : '')
        .'.</p>',
      isset($this->payload['service_title'])
        ? '<p>Service: <strong>'.e((string) $this->payload['service_title']).'</strong></p>'
        : '',
      '<p>Our counselling team will review your request and follow up shortly.</p>',
    ];
  }

  /**
   * @return list<string>
   */
  private function counsellingPaymentRequiredBody(): array
  {
    $currency = e((string) ($this->payload['currency'] ?? 'USD'));
    $amount = isset($this->payload['amount'])
      ? $currency.' '.e(number_format((float) $this->payload['amount'], 2))
      : '';

    return [
      '<p>Payment is required before your counselling session can be scheduled.</p>',
      isset($this->payload['case_number'])
        ? '<p>Case: <strong>'.e((string) $this->payload['case_number']).'</strong></p>'
        : '',
      $amount !== '' ? '<p>Amount due: <strong>'.$amount.'</strong></p>' : '',
      '<p>Sign in to your workspace to confirm payment.</p>',
    ];
  }

  /**
   * @return list<string>
   */
  private function counsellingPaymentReceivedBody(): array
  {
    return [
      '<p>We received your counselling payment. Thank you.</p>',
      isset($this->payload['case_number'])
        ? '<p>Case: <strong>'.e((string) $this->payload['case_number']).'</strong></p>'
        : '',
      '<p>Our team will confirm your appointment shortly.</p>',
    ];
  }

  /**
   * @param  'scheduled'|'reminder'|'rescheduled'  $kind
   * @return list<string>
   */
  private function counsellingAppointmentBody(string $kind): array
  {
    $intro = match ($kind) {
      'reminder' => '<p>This is a reminder for your upcoming counselling appointment.</p>',
      'rescheduled' => '<p>Your counselling appointment has been <strong>rescheduled</strong>.</p>',
      default => '<p>Your counselling appointment has been <strong>scheduled</strong>.</p>',
    };

    return [
      $intro,
      isset($this->payload['case_number'])
        ? '<p>Case: <strong>'.e((string) $this->payload['case_number']).'</strong></p>'
        : '',
      isset($this->payload['starts_at'])
        ? '<p><strong>When:</strong> '.e((string) $this->payload['starts_at']).'</p>'
        : '',
      isset($this->payload['format'])
        ? '<p><strong>Format:</strong> '.e((string) $this->payload['format']).'</p>'
        : '',
    ];
  }

  /**
   * @return list<string>
   */
  private function counsellingCancelledBody(): array
  {
    return [
      '<p>Your counselling case has been cancelled.</p>',
      isset($this->payload['case_number'])
        ? '<p>Case: <strong>'.e((string) $this->payload['case_number']).'</strong></p>'
        : '',
      isset($this->payload['reason'])
        ? '<p>Reason: '.e((string) $this->payload['reason']).'</p>'
        : '',
    ];
  }

  /**
   * @return list<string>
   */
  private function counsellingCompletedBody(): array
  {
    return [
      '<p>Your counselling case is marked <strong>complete</strong>.</p>',
      isset($this->payload['service_title'])
        ? '<p>Service: <strong>'.e((string) $this->payload['service_title']).'</strong></p>'
        : '',
      '<p>Thank you for trusting Marketplace Ministers with this journey.</p>',
    ];
  }

  /**
   * @return list<string>
   */
  private function counsellingFeedbackBody(): array
  {
    return [
      '<p>We would love your feedback on your counselling experience.</p>',
      isset($this->payload['case_number'])
        ? '<p>Case: <strong>'.e((string) $this->payload['case_number']).'</strong></p>'
        : '',
      '<p>Please sign in to your workspace to leave a short rating and comments.</p>',
    ];
  }
}
