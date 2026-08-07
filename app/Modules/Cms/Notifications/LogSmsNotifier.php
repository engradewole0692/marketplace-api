<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\SmsNotifierContract;
use Illuminate\Support\Facades\Log;

/**
 * Default SMS driver — logs intent until a provider is bound.
 */
final class LogSmsNotifier implements SmsNotifierContract
{
  public function send(string $to, string $message, array $context = []): bool
  {
    Log::info('cms.sms.dispatch', [
      'to' => $to,
      'message' => $message,
      'context' => $context,
    ]);

    return (bool) config('cms.notifications.sms_enabled', false);
  }
}
