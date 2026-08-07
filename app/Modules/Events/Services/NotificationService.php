<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\NotificationStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Mail\AdminNewRegistrationMail;
use App\Modules\Events\Mail\EventAnnouncementMail;
use App\Modules\Events\Mail\RegistrationConfirmationMail;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventNotificationLog;
use App\Modules\Events\Models\EventNotificationTemplate;
use App\Modules\Events\Models\EventRegistration;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class NotificationService implements ServiceContract
{
  public function __construct(
    private readonly MemberNotificationQueueService $memberNotificationQueueService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function templates(array $filters = []): LengthAwarePaginator
  {
    $query = EventNotificationTemplate::query()->with('event')->orderBy('name');

    if (! empty($filters['event_id'])) {
      $query->where('event_id', $filters['event_id']);
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createTemplate(array $data, User $actor): EventNotificationTemplate
  {
    return EventNotificationTemplate::query()->create([
      ...$data,
      'created_by_user_id' => $actor->id,
      'updated_by_user_id' => $actor->id,
    ]);
  }

  public function sendRegistrationNotifications(EventRegistration $registration, bool $created): void
  {
    $registration->loadMissing(['event.venue', 'event.ministry', 'member']);

    $failures = 0;
    $recipientEmail = $registration->contactEmail();

    if ($recipientEmail) {
      try {
        $this->sendMail(
          $registration,
          $recipientEmail,
          new RegistrationConfirmationMail($registration),
          'registration_confirmation',
          $created ? 'Registration confirmation' : 'Registration update confirmation',
        );
      } catch (\Throwable $exception) {
        $failures++;
        Log::error('Registration confirmation email failed', [
          'registration_id' => $registration->id,
          'recipient' => $recipientEmail,
          'error' => $exception->getMessage(),
        ]);
      }
    }

    if ($registration->member !== null) {
      $this->memberNotificationQueueService->queue(
        $registration->member,
        'email',
        'event_registration_confirmation',
        [
          'event_id' => $registration->event_id,
          'registration_id' => $registration->id,
          'registration_number' => $registration->registration_number,
        ],
      );
    }

    if ($created) {
      $adminEmail = (string) config('mail.admin_notification_email', config('app.admin_notification_email', ''));

      if ($adminEmail !== '') {
        try {
          $this->sendMail(
            $registration,
            $adminEmail,
            new AdminNewRegistrationMail($registration),
            'admin_new_registration',
            'Admin new registration alert',
          );
        } catch (\Throwable $exception) {
          $failures++;
          Log::error('Admin new registration email failed', [
            'registration_id' => $registration->id,
            'recipient' => $adminEmail,
            'error' => $exception->getMessage(),
          ]);
        }
      }
    }

    if ($failures > 0) {
      throw new \RuntimeException("{$failures} registration notification email(s) failed to send.");
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  public function queueEventNotification(EventRegistration $registration, string $template, array $payload = []): void
  {
    $registration->loadMissing('member');
    if ($registration->member === null) {
      return;
    }

    $this->memberNotificationQueueService->queue(
      $registration->member,
      'email',
      $template,
      array_merge([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'registration_number' => $registration->registration_number,
      ], $payload),
    );
  }

  public function scheduleReminder(EventRegistration $registration, ?\DateTimeInterface $sendAt = null): void
  {
    $registration->loadMissing('member');
    if ($registration->member === null) {
      return;
    }

    $this->memberNotificationQueueService->queue(
      $registration->member,
      'email',
      'event_schedule_reminder',
      [
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
      ],
      $sendAt !== null ? \Illuminate\Support\Carbon::instance($sendAt instanceof \DateTime ? $sendAt : new \DateTime((string) $sendAt->format('c'))) : null,
    );
  }

  public function scheduleChange(EventRegistration $registration, string $summary): void
  {
    $this->queueEventNotification($registration, 'event_schedule_change', ['summary' => $summary]);
  }

  public function checkInReminder(EventRegistration $registration): void
  {
    $this->queueEventNotification($registration, 'event_check_in_reminder');
  }

  public function certificateAvailable(EventRegistration $registration, string $verificationCode): void
  {
    $this->queueEventNotification($registration, 'event_certificate_issued', [
      'verification_code' => $verificationCode,
    ]);
  }

  public function volunteerAssigned(EventRegistration $registration, string $roleName): void
  {
    $this->queueEventNotification($registration, 'event_volunteer_assigned', ['role' => $roleName]);
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array{sent: int, failed: int, skipped: int}
   */
  public function sendAnnouncement(array $data, User $actor): array
  {
    $subject = (string) $data['subject'];
    $body = (string) $data['body'];
    $recipientScope = $data['recipient_scope'] ?? 'selected_event';

    $query = EventRegistration::query()->with(['member', 'event']);

    if ($recipientScope === 'everyone') {
      $query->whereNotIn('status', [RegistrationStatus::Cancelled->value, RegistrationStatus::Declined->value]);
    } elseif ($recipientScope === 'checked_in') {
      $query->where('status', RegistrationStatus::CheckedIn->value);
    } elseif ($recipientScope === 'checked_out') {
      $query->where('status', RegistrationStatus::Attended->value);
    } elseif ($recipientScope === 'selected_ministry' && ! empty($data['ministry_id'])) {
      $query->whereHas('event', fn ($q) => $q->where('ministry_id', $data['ministry_id']));
    } elseif (! empty($data['event_id'])) {
      $query->where('event_id', $data['event_id']);
    } else {
      throw new \InvalidArgumentException('An event, ministry, or recipient scope is required.');
    }

    if (! in_array($recipientScope, ['checked_in', 'checked_out'], true)) {
      $query->whereNotIn('status', [RegistrationStatus::Cancelled->value, RegistrationStatus::Declined->value]);
    }

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($query->cursor() as $registration) {
      $email = $registration->contactEmail();
      if (! $email) {
        $skipped++;
        continue;
      }

      $event = $registration->event ?? Event::query()->find($registration->event_id);

      try {
        $this->sendMail(
          $registration,
          $email,
          new EventAnnouncementMail($registration, $event, $subject, $body),
          'admin_announcement',
          $subject,
        );
        $sent++;
      } catch (\Throwable $exception) {
        $failed++;
        Log::warning('Announcement email failed', [
          'registration_id' => $registration->id,
          'error' => $exception->getMessage(),
        ]);
      }
    }

    return compact('sent', 'failed', 'skipped');
  }

  private function sendMail(
    EventRegistration $registration,
    string $recipient,
    Mailable $mailable,
    string $trigger,
    string $subject,
  ): void {
    $log = EventNotificationLog::query()->create([
      'event_id' => $registration->event_id,
      'registration_id' => $registration->id,
      'member_id' => $registration->member_id,
      'channel' => 'email',
      'recipient' => $recipient,
      'subject' => $subject,
      'status' => NotificationStatus::Queued,
      'queued_at' => now(),
      'metadata' => ['trigger' => $trigger],
    ]);

    try {
      Mail::to($recipient)->send($mailable);
      $log->update([
        'status' => NotificationStatus::Sent,
        'sent_at' => now(),
      ]);
    } catch (\Throwable $exception) {
      $log->update([
        'status' => NotificationStatus::Failed,
        'failed_at' => now(),
        'failure_reason' => $exception->getMessage(),
      ]);

      throw $exception;
    }
  }
}
