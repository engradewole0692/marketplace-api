<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Communications\Support\CommunicationWebhookUrls;
use App\Modules\Communications\Support\ProviderSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TwilioSmsNotifier implements SmsNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        return $this->dispatch($to, $message, $context)->accepted;
    }

    public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
    {
        $sid = (string) config('communications.twilio.account_sid');
        $token = (string) config('communications.twilio.auth_token');
        $from = (string) config('communications.twilio.from');
        $service = (string) config('communications.twilio.messaging_service_sid');
        if ($sid === '' || $token === '' || ($from === '' && $service === '')) {
            return ProviderSendResult::notConfigured('twilio', 'Twilio SMS requires TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM or TWILIO_MESSAGING_SERVICE_SID.');
        }

        $payload = ['To' => $to, 'Body' => $message];
        if ($service !== '') {
            $payload['MessagingServiceSid'] = $service;
        } else {
            $payload['From'] = $from;
        }
        $callback = CommunicationWebhookUrls::for('twilio');
        if (CommunicationWebhookUrls::isPublic($callback)) {
            $payload['StatusCallback'] = $callback;
        }

        return self::post($sid, $token, $payload);
    }

    /**
     * @param  array<string, string>  $payload
     */
    public static function post(string $sid, string $token, array $payload): ProviderSendResult
    {
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);
        } catch (ConnectionException $exception) {
            return ProviderSendResult::failed('twilio', 'connection_error', 'Could not reach Twilio.', true);
        }

        $json = $response->json() ?? [];
        if ($response->successful() && filled($json['sid'] ?? null)) {
            $price = isset($json['price']) && $json['price'] !== null ? abs((float) $json['price']) : null;

            return ProviderSendResult::accepted('twilio', (string) $json['sid'], (string) ($json['status'] ?? 'queued'), $price, isset($json['price_unit']) ? strtoupper((string) $json['price_unit']) : null);
        }

        Log::warning('communications.twilio.rejected', ['status' => $response->status(), 'code' => $json['code'] ?? null]);

        return ProviderSendResult::failed(
            'twilio',
            (string) ($json['code'] ?? 'http_'.$response->status()),
            (string) ($json['message'] ?? 'Twilio rejected the message.'),
            ProviderSendResult::retryableHttpStatus($response->status()),
        );
    }

    public function providerName(): string
    {
        return 'twilio';
    }

    public function checkCredentials(): array
    {
        return self::accountCheck();
    }

    /**
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public static function accountCheck(): array
    {
        $sid = (string) config('communications.twilio.account_sid');
        $token = (string) config('communications.twilio.auth_token');
        if ($sid === '' || $token === '') {
            return ['ok' => false, 'message' => 'Twilio credentials are missing.'];
        }
        try {
            $response = Http::timeout(15)->withBasicAuth($sid, $token)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach Twilio.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Twilio rejected the credentials (HTTP '.$response->status().').'];
        }

        return [
            'ok' => true,
            'message' => 'Twilio account reachable.',
            'details' => ['account_status' => $response->json('status'), 'type' => $response->json('type')],
        ];
    }
}
