<?php

declare(strict_types=1);

namespace App\Modules\Lms\Mail;

use App\Modules\Lms\Models\CourseCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CourseCertificateIssuedMail extends Mailable
{
  use Queueable;
  use SerializesModels;

  public function __construct(
    public readonly CourseCertificate $certificate,
    public readonly string $recipientName,
  ) {}

  public function envelope(): Envelope
  {
    return new Envelope(subject: 'Your course certificate is ready');
  }

  public function content(): Content
  {
    $this->certificate->loadMissing(['course', 'certificateMedia']);
    $verifyUrl = url('/certificate/'.$this->certificate->verification_code);
    $downloadUrl = $this->certificate->certificateMedia?->url();

    $html = '<p>Hello '.e($this->recipientName).',</p>'
      .'<p>Congratulations — your certificate for <strong>'
      .e((string) ($this->certificate->course?->title ?? 'your course'))
      .'</strong> has been issued.</p>'
      .'<p>Certificate number: <code>'.e($this->certificate->certificate_number).'</code></p>'
      .'<p><a href="'.e($verifyUrl).'">Verify certificate</a></p>';

    if ($downloadUrl) {
      $html .= '<p><a href="'.e(url($downloadUrl)).'">Download PDF</a></p>';
    }

    $html .= '<p>Marketplace Ministers</p>';

    return new Content(htmlString: $html);
  }
}
