<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

interface SmsNotifierContract
{
  /**
   * Integration point for SMS providers (Twilio, Africa's Talking, etc.).
   */
  public function send(string $to, string $message, array $context = []): bool;
}
