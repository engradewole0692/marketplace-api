<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Communications\Support\ProviderSendResult;

interface WhatsAppNotifierContract
{
  /**
   * Integration point for WhatsApp Business API providers. Free-form text is only valid inside
   * an open customer-service window; business-initiated messages must use dispatchTemplate().
   */
  public function send(string $to, string $message, array $context = []): bool;

  /**
   * @param  array<string, mixed>  $context
   */
  public function dispatch(string $to, string $message, array $context = []): ProviderSendResult;

  /**
   * Send an approved template.
   *
   * @param  array{name: string, language: string, parameters?: list<string>, provider_template_id?: ?string}  $template
   * @param  array<string, mixed>  $context
   */
  public function dispatchTemplate(string $to, array $template, array $context = []): ProviderSendResult;

  public function providerName(): string;

  /**
   * @return array{ok: bool, message: string, details?: array<string, mixed>}
   */
  public function checkCredentials(): array;
}
