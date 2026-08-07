<?php

declare(strict_types=1);

namespace App\Modules\Events\Mail;

use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\EventPresentation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class RegistrationConfirmationMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(public readonly EventRegistration $registration) {}

  public function envelope(): Envelope
  {
    $event = $this->registration->event;

    return new Envelope(
      subject: 'Registration confirmed — '.($event?->title ?? 'Event'),
    );
  }

  public function content(): Content
  {
    $this->registration->loadMissing(['event.venue', 'event.ministry', 'member']);
    $event = $this->registration->event;

    return new Content(
      htmlString: sprintf(
        '<p>Hi %s,</p><p>Your registration for <strong>%s</strong> is confirmed.</p>'
        .'<p>Registration number: <strong>%s</strong></p><p>Date: %s<br>Time: %s<br>Venue: %s</p>',
        e($this->registration->contactName() ?: 'there'),
        e($event?->title ?? 'the event'),
        e($this->registration->registration_number),
        e(EventPresentation::eventDate($event) ?? 'TBA'),
        e(EventPresentation::eventTime($event) ?? 'TBA'),
        e(EventPresentation::venue($event) ?? 'TBA'),
      ),
    );
  }
}
