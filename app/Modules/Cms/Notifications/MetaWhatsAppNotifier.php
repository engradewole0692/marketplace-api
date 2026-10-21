<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Support\ProviderSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Platform (Meta Cloud API).
 */
final class MetaWhatsAppNotifier implements WhatsAppNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        return $this->dispatch($to, $message, $context)->accepted;
    }

    public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
    {
        return $this->post($to, ['type' => 'text', 'text' => ['body' => $message]]);
    }

    public function dispatchTemplate(string $to, array $template, array $context = []): ProviderSendResult
    {
        $parameters = array_map(
            fn ($value): array => ['type' => 'text', 'text' => (string) $value],
            array_values($template['parameters'] ?? []),
        );
        $body = [
            'name' => (string) $template['name'],
            'language' => ['code' => (string) $template['language']],
        ];
        if ($parameters !== []) {
            $body['components'] = [['type' => 'body', 'parameters' => $parameters]];
        }

        return $this->post($to, ['type' => 'template', 'template' => $body]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function post(string $to, array $content): ProviderSendResult
    {
        $token = (string) config('communications.meta_whatsapp.token');
        $phoneNumberId = (string) config('communications.meta_whatsapp.phone_number_id');
        if ($token === '' || $phoneNumberId === '') {
            return ProviderSendResult::notConfigured('meta', 'Meta WhatsApp requires WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID.');
        }

        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($digits === '') {
            return ProviderSendResult::failed('meta', 'invalid_phone', 'Destination is not a valid phone number.');
        }

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post($this->graph()."/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $digits,
                    ...$content,
                ]);
        } catch (ConnectionException) {
            return ProviderSendResult::failed('meta', 'connection_error', 'Could not reach the WhatsApp Cloud API.', true);
        }

        $messageId = $response->json('messages.0.id');
        if ($response->successful() && is_string($messageId) && $messageId !== '') {
            return ProviderSendResult::accepted('meta', $messageId, (string) ($response->json('messages.0.message_status') ?? 'accepted'));
        }

        Log::warning('communications.meta_whatsapp.rejected', ['status' => $response->status(), 'code' => $response->json('error.code')]);

        return ProviderSendResult::failed(
            'meta',
            (string) ($response->json('error.code') ?? 'http_'.$response->status()),
            (string) ($response->json('error.error_data.details') ?? $response->json('error.message') ?? 'WhatsApp Cloud API rejected the message.'),
            ProviderSendResult::retryableHttpStatus($response->status()),
        );
    }

    public function providerName(): string
    {
        return 'meta';
    }

    public function checkCredentials(): array
    {
        $token = (string) config('communications.meta_whatsapp.token');
        $phoneNumberId = (string) config('communications.meta_whatsapp.phone_number_id');
        if ($token === '' || $phoneNumberId === '') {
            return ['ok' => false, 'message' => 'Meta WhatsApp credentials are missing.'];
        }
        try {
            $response = Http::withToken($token)->timeout(15)
                ->get($this->graph()."/{$phoneNumberId}", ['fields' => 'display_phone_number,verified_name,quality_rating']);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach the WhatsApp Cloud API.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Meta rejected the credentials: '.($response->json('error.message') ?? 'HTTP '.$response->status())];
        }

        return [
            'ok' => true,
            'message' => 'WhatsApp phone number reachable.',
            'details' => [
                'verified_name' => $response->json('verified_name'),
                'quality_rating' => $response->json('quality_rating'),
            ],
        ];
    }

    /**
     * Approved-template list from the WhatsApp Business Account.
     *
     * @return array{ok: bool, message: string, templates?: list<array<string, mixed>>}
     */
    public function fetchTemplates(): array
    {
        $token = (string) config('communications.meta_whatsapp.token');
        $waba = (string) config('communications.meta_whatsapp.business_account_id');
        if ($token === '' || $waba === '') {
            return ['ok' => false, 'message' => 'Template sync requires WHATSAPP_ACCESS_TOKEN and WHATSAPP_BUSINESS_ACCOUNT_ID.'];
        }
        try {
            $response = Http::withToken($token)->timeout(20)
                ->get($this->graph()."/{$waba}/message_templates", ['fields' => 'name,language,status,category,components', 'limit' => 200]);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach the WhatsApp Cloud API.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Template sync failed: '.($response->json('error.message') ?? 'HTTP '.$response->status())];
        }

        return ['ok' => true, 'message' => 'Templates fetched.', 'templates' => (array) ($response->json('data') ?? [])];
    }

    private function graph(): string
    {
        return 'https://graph.facebook.com/'.config('communications.meta_whatsapp.graph_version', 'v21.0');
    }
}
