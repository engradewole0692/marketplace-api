<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Communications\Support\ProviderSendResult;

interface SmsNotifierContract
{
  /**
   * Integration point for SMS providers. Returns true only when the provider accepted the message.
   */
  public function send(string $to, string $message, array $context = []): bool;

  /**
   * Send and return provider evidence (message id, status, error code, cost).
   *
   * @param  array<string, mixed>  $context
   */
  public function dispatch(string $to, string $message, array $context = []): ProviderSendResult;

  public function providerName(): string;

  /**
   * Credential/account check that sends no message.
   *
   * @return array{ok: bool, message: string, details?: array<string, mixed>}
   */
  public function checkCredentials(): array;
}
