<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Models\CmsFormSubmissionEvent;

/**
 * Maps CMS form submissions to central communication dispatch.
 */
final class CommunicationFormBridge implements ServiceContract
{
  public function __construct(
    private readonly CommunicationDispatchService $dispatch,
  ) {}

  public function dispatchForSubmission(CmsFormSubmission $submission): void
  {
    $type = $submission->type;
    $payload = $submission->payload ?? [];
    $applicantName = (string) ($submission->submitter_name ?? $payload['name'] ?? $payload['fullName'] ?? 'Friend');
    $email = (string) ($submission->submitter_email ?? $payload['email'] ?? '');

    [$section, $eventKey, $adminEventKey] = $this->mapType($type);
    if ($eventKey === null) {
      return;
    }

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $variables = [
      'applicant_name' => $applicantName,
      'member_name' => $applicantName,
      'visitor_name' => $applicantName,
      'email' => $email,
      'phone' => (string) ($payload['phone'] ?? $payload['whatsapp'] ?? ''),
      'subject' => (string) ($payload['subject'] ?? ''),
      'message' => (string) ($payload['message'] ?? $payload['reason'] ?? ''),
      'application_number' => (string) ($payload['application_number'] ?? ''),
      'case_number' => (string) ($payload['case_number'] ?? ''),
      'login_url' => $frontend.'/login',
      'dashboard_url' => $frontend.'/portal',
    ];

    $context = [
      'assigned_admin_user_id' => $submission->assignee_id,
      'form_submission_id' => $submission->uuid,
    ];

    if ($email !== '') {
      try {
        $this->dispatch->dispatchEvent(
          eventKey: $eventKey,
          section: $section,
          variables: $variables,
          context: $context,
          recipientEmail: $email,
          recipientName: $applicantName,
          related: $submission,
          includeRouting: false,
        );
        $submission->forceFill(['email_notified_at' => now()])->save();
        $this->timeline($submission, 'email', 'Confirmation email sent', $email);
      } catch (\Throwable $exception) {
        report($exception);
        $this->timeline($submission, 'email_failed', 'Confirmation email failed', $exception->getMessage());
      }
    }

    if ($adminEventKey !== null) {
      try {
        $this->dispatch->dispatchEvent(
          eventKey: $adminEventKey,
          section: $section,
          variables: $variables,
          context: $context,
          related: $submission,
          includeRouting: true,
        );
        $this->timeline($submission, 'email', 'Admin notification dispatched', $adminEventKey);
      } catch (\Throwable $exception) {
        report($exception);
        $this->timeline($submission, 'email_failed', 'Admin notification failed', $exception->getMessage());
      }
    }
  }

  /**
   * @return array{0: string, 1: ?string, 2: ?string}
   */
  private function mapType(FormSubmissionType $type): array
  {
    return match ($type) {
      FormSubmissionType::Contact => ['contact', 'form.contact.submitted', 'form.contact.submitted.admin'],
      FormSubmissionType::Prayer => ['prayer', 'form.prayer.submitted', 'form.prayer.submitted.admin'],
      FormSubmissionType::Counseling => ['counseling', 'form.counseling.submitted', 'form.counseling.submitted.admin'],
      FormSubmissionType::MembershipApplication => ['membership', 'form.membership.submitted', 'form.membership.submitted.admin'],
      FormSubmissionType::Partnership => ['partnership', 'form.partnership.submitted', 'form.partnership.submitted.admin'],
      FormSubmissionType::Newsletter => ['newsletter', 'form.newsletter.submitted', 'form.newsletter.submitted.admin'],
      FormSubmissionType::DonationInterest => ['donations', 'form.donation.submitted', 'form.donation.submitted.admin'],
      FormSubmissionType::Volunteer => ['events', 'form.volunteer.submitted', 'form.volunteer.submitted.admin'],
      FormSubmissionType::Testimony => ['contact', 'form.testimony.submitted', 'form.testimony.submitted.admin'],
    };
  }

  private function timeline(CmsFormSubmission $submission, string $type, string $title, ?string $body = null): void
  {
    CmsFormSubmissionEvent::query()->create([
      'submission_id' => $submission->id,
      'actor_id' => null,
      'event_type' => $type,
      'title' => $title,
      'body' => $body,
      'meta' => null,
    ]);
  }
}
