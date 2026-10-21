<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

/**
 * Outcome of handing one message to an SMS/WhatsApp provider. Acceptance is not delivery.
 */
final class ProviderSendResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?float $cost = null,
        public readonly ?string $currency = null,
        public readonly bool $retryable = false,
    ) {}

    public static function accepted(string $provider, ?string $messageId, ?string $providerStatus = null, ?float $cost = null, ?string $currency = null): self
    {
        return new self(true, $provider, $messageId, $providerStatus, null, null, $cost, $currency);
    }

    public static function failed(string $provider, string $errorCode, string $errorMessage, bool $retryable = false): self
    {
        return new self(false, $provider, null, null, $errorCode, mb_substr($errorMessage, 0, 500), null, null, $retryable);
    }

    public static function notConfigured(string $provider, string $message): self
    {
        return new self(false, $provider, null, null, 'provider_not_configured', $message);
    }

    /**
     * Retry only throttling and server-side failures; 4xx validation errors are permanent.
     */
    public static function retryableHttpStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }
}
