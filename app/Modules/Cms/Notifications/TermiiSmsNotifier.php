<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Communications\Support\ProviderSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Termii SMS (Nigeria-focused). Promotional traffic always uses the generic route, which honours DND;
 * the DND route is only used for transactional messages when explicitly enabled.
 */
final class TermiiSmsNotifier implements SmsNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        return $this->dispatch($to, $message, $context)->accepted;
    }

    public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
    {
        $apiKey = (string) config('communications.termii.api_key');
        $sender = (string) config('communications.termii.sender_id');
        if ($apiKey === '' || $sender === '') {
            return ProviderSendResult::notConfigured('termii', 'Termii requires TERMII_API_KEY and an approved TERMII_SENDER_ID.');
        }

        $transactional = ($context['message_type'] ?? 'promotional') === 'transactional';
        $channel = $transactional && config('communications.termii.transactional_dnd_route') ? 'dnd' : 'generic';

        try {
            $response = Http::timeout(20)->acceptJson()->post($this->base().'/api/sms/send', [
                'api_key' => $apiKey,
                'to' => preg_replace('/\D+/', '', $to) ?? '',
                'from' => $sender,
                'sms' => $message,
                'type' => 'plain',
                'channel' => $channel,
            ]);
        } catch (ConnectionException) {
            return ProviderSendResult::failed('termii', 'connection_error', 'Could not reach Termii.', true);
        }

        $messageId = $response->json('message_id');
        if ($response->successful() && filled($messageId)) {
            return ProviderSendResult::accepted('termii', (string) $messageId, (string) ($response->json('message') ?? 'accepted'));
        }

        Log::warning('communications.termii.rejected', ['status' => $response->status()]);

        return ProviderSendResult::failed(
            'termii',
            'http_'.$response->status(),
            (string) ($response->json('message') ?? 'Termii rejected the message.'),
            ProviderSendResult::retryableHttpStatus($response->status()),
        );
    }

    public function providerName(): string
    {
        return 'termii';
    }

    public function checkCredentials(): array
    {
        $apiKey = (string) config('communications.termii.api_key');
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'TERMII_API_KEY is missing.'];
        }
        try {
            $response = Http::timeout(15)->acceptJson()->get($this->base().'/api/get-balance', ['api_key' => $apiKey]);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach Termii.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Termii rejected the API key (HTTP '.$response->status().').'];
        }

        return [
            'ok' => true,
            'message' => 'Termii account reachable.',
            'details' => ['balance' => $response->json('balance'), 'currency' => $response->json('currency')],
        ];
    }

    private function base(): string
    {
        return rtrim((string) config('communications.termii.base_url', 'https://api.ng.termii.com'), '/');
    }
}
