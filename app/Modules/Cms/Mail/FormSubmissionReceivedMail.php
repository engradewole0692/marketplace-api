<?php

declare(strict_types=1);

namespace App\Modules\Cms\Mail;

use App\Modules\Cms\Models\CmsFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FormSubmissionReceivedMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(public readonly CmsFormSubmission $submission) {}

  public function envelope(): Envelope
  {
    return new Envelope(
      subject: 'We received your '.$this->submission->type->value.' submission',
    );
  }

  public function content(): Content
  {
    return new Content(
      htmlString: sprintf(
        '<p>Hello %s,</p><p>Thank you — your <strong>%s</strong> submission was received and our team will follow up.</p><p>— Marketplace Ministers</p>',
        e($this->submission->submitter_name ?: 'friend'),
        e($this->submission->type->value),
      ),
    );
  }
}
