<?php

declare(strict_types=1);

namespace App\Modules\Cms\Mail;

use App\Modules\Cms\Models\CmsFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FormSubmissionAdminAlertMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(public readonly CmsFormSubmission $submission) {}

  public function envelope(): Envelope
  {
    return new Envelope(
      subject: '[Inbox] New '.$this->submission->type->value.' submission',
    );
  }

  public function content(): Content
  {
    return new Content(
      htmlString: sprintf(
        '<p>A new <strong>%s</strong> submission arrived.</p><p>From: %s (%s)</p><p>Open Admin Inbox to review.</p>',
        e($this->submission->type->value),
        e($this->submission->submitter_name ?: 'Unknown'),
        e($this->submission->submitter_email ?: 'no email'),
      ),
    );
  }
}
