<?php

declare(strict_types=1);

namespace App\Modules\Events\Mail;

use App\Modules\Events\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AdminNewRegistrationMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(public readonly EventRegistration $registration) {}

  public function envelope(): Envelope
  {
    return new Envelope(
      subject: 'New registration — '.($this->registration->contactName() ?? 'Attendee'),
    );
  }

  public function content(): Content
  {
    $this->registration->loadMissing(['event', 'member']);

    return new Content(
      htmlString: sprintf(
        '<p>A new registration was submitted for <strong>%s</strong>.</p>'
        .'<p>Name: %s<br>Email: %s<br>Registration number: %s</p>',
        e($this->registration->event?->title ?? 'an event'),
        e($this->registration->contactName() ?: 'Unknown'),
        e($this->registration->contactEmail() ?: 'no email'),
        e($this->registration->registration_number),
      ),
    );
  }
}
