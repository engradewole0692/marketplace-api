<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

final class VerifyEmail extends BaseVerifyEmail
{
  /**
   * @param  mixed  $notifiable
   * @return list<string>
   */
  public function via($notifiable): array
  {
    $this->dispatchCommunications($notifiable);

    return [];
  }

  /**
   * @param  mixed  $notifiable
   */
  private function dispatchCommunications($notifiable): void
  {
    $email = is_object($notifiable) ? (string) ($notifiable->email ?? '') : '';
    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return;
    }

    $name = is_object($notifiable)
      ? (string) ($notifiable->display_name ?: $notifiable->name ?: 'Friend')
      : 'Friend';
    $frontend = rtrim((string) config('app-frontend.url', env('FRONTEND_URL', 'http://localhost:8081')), '/');

    try {
      app(\App\Modules\Communications\Services\CommunicationDispatchService::class)->dispatchEvent(
        eventKey: \App\Modules\Communications\Support\CommunicationEventKeys::AUTH_EMAIL_VERIFICATION,
        section: 'learning',
        variables: [
          'applicant_name' => $name,
          'email' => $email,
          'verification_url' => $this->verificationUrl($notifiable),
          'login_url' => $frontend.'/learn/login',
        ],
        recipientUser: $notifiable instanceof \App\Models\User ? $notifiable : null,
        recipientEmail: $email,
        recipientName: $name,
        includeRouting: false,
        idempotencyKey: \App\Modules\Communications\Support\CommunicationEventKeys::AUTH_EMAIL_VERIFICATION.':'.$email.':'.now()->format('Y-m-d-H'),
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
  /**
   * @param  mixed  $notifiable
   */
  protected function verificationUrl($notifiable): string
  {
    $apiUrl = URL::temporarySignedRoute(
      'api.v1.auth.verification.verify',
      Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
      [
        'id' => $notifiable->getKey(),
        'hash' => sha1($notifiable->getEmailForVerification()),
      ],
    );

    $parts = parse_url($apiUrl);
    parse_str($parts['query'] ?? '', $query);
    $frontend = rtrim((string) config('app-frontend.url', env('FRONTEND_URL', 'http://localhost:8081')), '/');

    return $frontend.'/learn/verify-email?'.http_build_query([
      'id' => (string) $notifiable->getKey(),
      'hash' => sha1($notifiable->getEmailForVerification()),
      'expires' => $query['expires'] ?? null,
      'signature' => $query['signature'] ?? null,
    ]);
  }
}
