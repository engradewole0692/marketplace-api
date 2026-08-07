<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

interface WhatsAppNotifierContract
{
  /**
   * Integration point for WhatsApp Business API providers.
   */
  public function send(string $to, string $message, array $context = []): bool;
}
