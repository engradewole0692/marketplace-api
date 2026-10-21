<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Modules\Communications\Support\ProviderSendResult;
use Illuminate\Support\Facades\Log;

/**
 * Default WhatsApp driver used when no provider is configured. Never reports a message as sent.
 */
final class LogWhatsAppNotifier implements WhatsAppNotifierContract
{
  public function send(string $to, string $message, array $context = []): bool
  {
    return $this->dispatch($to, $message, $context)->accepted;
  }

  public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
  {
    Log::info('cms.whatsapp.not_sent', ['to' => PhoneNumberService::mask($to)]);

    return ProviderSendResult::notConfigured('log', 'WhatsApp provider is not configured. Set WHATSAPP_PROVIDER and credentials before sending.');
  }

  public function dispatchTemplate(string $to, array $template, array $context = []): ProviderSendResult
  {
    return $this->dispatch($to, '', $context);
  }

  public function providerName(): string
  {
    return 'log';
  }

  public function checkCredentials(): array
  {
    return ['ok' => false, 'message' => 'No WhatsApp provider is configured.'];
  }
}
