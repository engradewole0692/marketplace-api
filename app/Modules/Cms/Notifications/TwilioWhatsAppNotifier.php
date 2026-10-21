<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Support\CommunicationWebhookUrls;
use App\Modules\Communications\Support\ProviderSendResult;

final class TwilioWhatsAppNotifier implements WhatsAppNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        return $this->dispatch($to, $message, $context)->accepted;
    }

    public function dispatch(string $to, string $message, array $context = []): ProviderSendResult
    {
        return $this->post($to, ['Body' => $message]);
    }

    public function dispatchTemplate(string $to, array $template, array $context = []): ProviderSendResult
    {
        $contentSid = (string) ($template['provider_template_id'] ?? '');
        if ($contentSid === '') {
            return ProviderSendResult::failed('twilio', 'template_not_mapped', 'Twilio WhatsApp templates require a Content SID (HX...).');
        }
        $variables = [];
        foreach (array_values($template['parameters'] ?? []) as $index => $value) {
            $variables[(string) ($index + 1)] = (string) $value;
        }

        return $this->post($to, [
            'ContentSid' => $contentSid,
            'ContentVariables' => json_encode($variables, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }

    /**
     * @param  array<string, string>  $content
     */
    private function post(string $to, array $content): ProviderSendResult
    {
        $sid = (string) config('communications.twilio.account_sid');
        $token = (string) config('communications.twilio.auth_token');
        $from = (string) config('communications.twilio.whatsapp_from');
        if ($sid === '' || $token === '' || $from === '') {
            return ProviderSendResult::notConfigured('twilio', 'Twilio WhatsApp requires TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_WHATSAPP_FROM.');
        }

        $payload = [
            'To' => str_starts_with(strtolower($to), 'whatsapp:') ? $to : 'whatsapp:'.$to,
            'From' => str_starts_with(strtolower($from), 'whatsapp:') ? $from : 'whatsapp:'.$from,
            ...$content,
        ];
        $callback = CommunicationWebhookUrls::for('twilio');
        if (CommunicationWebhookUrls::isPublic($callback)) {
            $payload['StatusCallback'] = $callback;
        }

        return TwilioSmsNotifier::post($sid, $token, $payload);
    }

    public function providerName(): string
    {
        return 'twilio';
    }

    public function checkCredentials(): array
    {
        return TwilioSmsNotifier::accountCheck();
    }
}
