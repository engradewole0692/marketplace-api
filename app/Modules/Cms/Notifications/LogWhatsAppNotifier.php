<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use Illuminate\Support\Facades\Log;

/**
 * Default WhatsApp driver — logs intent until a provider is bound.
 */
final class LogWhatsAppNotifier implements WhatsAppNotifierContract
{
  public function send(string $to, string $message, array $context = []): bool
  {
    Log::info('cms.whatsapp.dispatch', [
      'to' => $to,
      'message' => $message,
      'context' => $context,
    ]);

    return (bool) config('cms.notifications.whatsapp_enabled', false);
  }
}
