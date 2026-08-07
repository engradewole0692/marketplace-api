<?php

declare(strict_types=1);

namespace App\Modules\Events\Mail;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventAnnouncementMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(
    public readonly EventRegistration $registration,
    public readonly Event $event,
    public readonly string $announcementSubject,
    public readonly string $announcementBody,
  ) {}

  public function envelope(): Envelope
  {
    return new Envelope(subject: $this->announcementSubject);
  }

  public function content(): Content
  {
    return new Content(
      htmlString: sprintf(
        '<p>Hi %s,</p><div>%s</div>',
        e($this->registration->contactName() ?: 'there'),
        $this->announcementBody,
      ),
    );
  }
}
