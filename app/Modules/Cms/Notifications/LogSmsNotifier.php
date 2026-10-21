<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Modules\Communications\Support\ProviderSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Default SMS driver used when no provider is configured. Never reports a message as sent.
 */
final class LogSmsNotifier implements SmsNotifierContract
{
  public function send(string $to, string $message, array $context = []): bool
  {
    return $this->dispatch($to, $message, $context)->accepted;
  }

  public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
  {
    Log::info('cms.sms.not_sent', [
      'to' => PhoneNumberService::mask($to),
      'length' => mb_strlen($message),
    ]);

    return ProviderSendResult::notConfigured('log', 'SMS provider is not configured. Set SMS_PROVIDER and credentials before sending.');
  }

  public function providerName(): string
  {
    return 'log';
  }

  public function checkCredentials(): array
  {
    return ['ok' => false, 'message' => 'No SMS provider is configured.'];
  }
}
