<?php

declare(strict_types=1);

namespace App\Modules\Communications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CommunicationMailable extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(
    public readonly string $mailSubject,
    public readonly string $htmlBody,
    public readonly ?string $textBody = null,
    public readonly ?string $replyToEmail = null,
    public readonly ?string $replyToName = null,
    public readonly ?string $fromName = null,
  ) {}

  public function envelope(): Envelope
  {
    $envelope = new Envelope(subject: $this->mailSubject);
    if ($this->replyToEmail) {
      $envelope = $envelope->replyTo([new Address($this->replyToEmail, $this->replyToName ?? '')]);
    }
    if ($this->fromName) {
      $envelope = $envelope->from(new Address(
        (string) config('mail.from.address'),
        $this->fromName,
      ));
    }

    return $envelope;
  }

  public function content(): Content
  {
    return new Content(htmlString: $this->htmlBody);
  }
}
