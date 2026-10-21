<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

final class CommunicationWebhookUrls
{
    public static function for(string $provider): string
    {
        if ($provider === 'twilio' && filled(config('communications.twilio.webhook_url'))) {
            return (string) config('communications.twilio.webhook_url');
        }

        return rtrim((string) config('app.url'), '/').'/api/v1/communications/webhooks/'.$provider;
    }

    /**
     * Providers cannot reach localhost, so callbacks are only requested for public URLs.
     */
    public static function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)
            && ! str_ends_with($host, '.test') && ! str_ends_with($host, '.local');
    }
}
