<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\MemberNotificationMail;
use App\Models\MemberNotificationQueue;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Cms\Notifications\LogSmsNotifier;
use App\Modules\Cms\Notifications\LogWhatsAppNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ProcessMemberNotificationJob extends BaseJob
{
  public function __construct(
    public readonly int $notificationId,
  ) {}

  public function handle(): void
  {
    $item = MemberNotificationQueue::query()->with('member')->find($this->notificationId);
    if ($item === null) {
      return;
    }

    if (in_array((string) $item->status, ['sent', 'cancelled'], true)) {
      return;
    }

    $item->forceFill([
      'status' => 'processing',
      'processing_at' => now(),
      'attempts' => ((int) $item->attempts) + 1,
    ])->save();

    try {
      $payload = is_array($item->payload) ? $item->payload : [];
      $channel = (string) $item->channel;

      match ($channel) {
        'email' => $this->sendEmail($item, $payload),
        'whatsapp' => $this->sendWhatsApp($item, $payload),
        'push', 'sms' => $this->sendSms($item, $payload),
        'in_app' => null,
        default => throw new \RuntimeException("Unsupported notification channel [{$channel}]."),
      };

      $item->forceFill([
        'status' => 'sent',
        'sent_at' => now(),
        'error' => null,
      ])->save();
    } catch (Throwable $exception) {
      Log::warning('Member notification dispatch failed', [
        'notification_id' => $item->id,
        'error' => $exception->getMessage(),
      ]);

      $item->forceFill([
        'status' => 'failed',
        'error' => $exception->getMessage(),
      ])->save();
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function sendEmail(MemberNotificationQueue $item, array $payload): void
  {
    $email = (string) ($payload['email'] ?? $item->member?->email ?? '');
    if ($email === '') {
      throw new \RuntimeException('Notification email address is missing.');
    }

    Mail::to($email)->send(new MemberNotificationMail(
      template: (string) $item->template,
      payload: $payload,
      memberName: $item->member?->fullName() ?? 'Member',
    ));
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function sendWhatsApp(MemberNotificationQueue $item, array $payload): void
  {
    $phone = (string) ($payload['phone'] ?? $item->member?->phone ?? '');
    if ($phone === '') {
      throw new \RuntimeException('WhatsApp phone number is missing.');
    }

    $notifier = app()->bound(WhatsAppNotifierContract::class)
      ? app(WhatsAppNotifierContract::class)
      : new LogWhatsAppNotifier;

    $notifier->send($phone, 'Template: '.$item->template.' '.json_encode($payload));
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function sendSms(MemberNotificationQueue $item, array $payload): void
  {
    $phone = (string) ($payload['phone'] ?? $item->member?->phone ?? '');
    if ($phone === '') {
      throw new \RuntimeException('SMS phone number is missing.');
    }

    $notifier = app()->bound(SmsNotifierContract::class)
      ? app(SmsNotifierContract::class)
      : new LogSmsNotifier;

    $notifier->send($phone, 'Template: '.$item->template);
  }
}
