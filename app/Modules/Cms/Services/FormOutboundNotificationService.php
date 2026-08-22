<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Models\CmsFormSubmissionEvent;

final class FormOutboundNotificationService implements ServiceContract
{
  public function __construct(
    private readonly SmsNotifierContract $sms,
    private readonly WhatsAppNotifierContract $whatsApp,
  ) {}

  public function dispatchForSubmission(CmsFormSubmission $submission): void
  {
    $this->sendSmsHook($submission);
    $this->sendWhatsAppHook($submission);
  }

  public function sendSubmitterEmail(CmsFormSubmission $submission): void
  {
    // Email is owned by CommunicationFormBridge. Kept as a no-op so callers cannot double-send.
  }

  public function sendAdminEmail(CmsFormSubmission $submission): void
  {
    // Email is owned by CommunicationFormBridge. Kept as a no-op so callers cannot double-send.
  }

  public function sendSmsHook(CmsFormSubmission $submission): void
  {
    $phone = $submission->submitter_phone
      ?? ($submission->payload['phone'] ?? null);

    if (! is_string($phone) || $phone === '') {
      return;
    }

    $sent = $this->sms->send(
      $phone,
      sprintf('Marketplace Ministers received your %s submission. We will follow up soon.', $submission->type->value),
      ['submission_id' => $submission->uuid, 'channel' => 'sms'],
    );

    if ($sent) {
      $submission->forceFill(['sms_notified_at' => now()])->save();
    }

    $this->timeline(
      $submission,
      $sent ? 'sms' : 'sms_queued',
      $sent ? 'SMS dispatched' : 'SMS integration point invoked',
      $phone,
    );
  }

  public function sendWhatsAppHook(CmsFormSubmission $submission): void
  {
    $phone = $submission->submitter_phone
      ?? ($submission->payload['phone'] ?? ($submission->payload['whatsapp'] ?? null));

    if (! is_string($phone) || $phone === '') {
      return;
    }

    $sent = $this->whatsApp->send(
      $phone,
      sprintf('Thank you — Marketplace Ministers received your %s request.', $submission->type->value),
      ['submission_id' => $submission->uuid, 'channel' => 'whatsapp'],
    );

    if ($sent) {
      $submission->forceFill(['whatsapp_notified_at' => now()])->save();
    }

    $this->timeline(
      $submission,
      $sent ? 'whatsapp' : 'whatsapp_queued',
      $sent ? 'WhatsApp dispatched' : 'WhatsApp integration point invoked',
      $phone,
    );
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
