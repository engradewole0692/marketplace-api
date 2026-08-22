<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Communications\Support\CommunicationEventKeys;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

final class ResetPassword extends BaseResetPassword
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

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $name = is_object($notifiable)
      ? (string) ($notifiable->display_name ?: $notifiable->name ?: 'Friend')
      : 'Friend';
    $url = $frontend.'/learn/reset-password?token='.urlencode($this->token).'&email='.urlencode($email);

    try {
      app(CommunicationDispatchService::class)->dispatchEvent(
        eventKey: CommunicationEventKeys::AUTH_PASSWORD_RESET,
        section: 'learning',
        variables: [
          'applicant_name' => $name,
          'email' => $email,
          'reset_url' => $url,
          'login_url' => $frontend.'/learn/login',
        ],
        recipientUser: $notifiable instanceof \App\Models\User ? $notifiable : null,
        recipientEmail: $email,
        recipientName: $name,
        includeRouting: false,
        idempotencyKey: CommunicationEventKeys::AUTH_PASSWORD_RESET.':'.$email.':'.sha1($this->token),
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
