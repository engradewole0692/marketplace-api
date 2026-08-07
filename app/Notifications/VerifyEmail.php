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
