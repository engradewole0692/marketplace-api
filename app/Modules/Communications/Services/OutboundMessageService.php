<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use App\Modules\Events\Support\PhoneNumberNormalizer;

final class OutboundMessageService implements ServiceContract
{
    public function __construct(
        private readonly SmsNotifierContract $sms,
        private readonly WhatsAppNotifierContract $whatsapp,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $channel, string $to, string $body, array $context = [], ?User $actor = null): CommunicationOutboundMessage
    {
        $channel = strtolower($channel);
        $normalized = PhoneNumberNormalizer::normalize($to, $context['phone_country_code'] ?? null);
        $destination = in_array($channel, ['sms', 'whatsapp'], true)
            ? ($normalized['phone'] ?? $to)
            : $to;

        $provider = $channel === 'email'
            ? (string) config('mail.default')
            : (string) config('communications.'.($channel === 'whatsapp' ? 'whatsapp_provider' : 'sms_provider'));

        $row = CommunicationOutboundMessage::query()->create([
            'channel' => $channel,
            'to' => $destination,
            'status' => 'queued',
            'provider' => $provider,
            'body' => $body,
            'subject' => $context['subject'] ?? null,
            'metadata' => ['context' => array_keys($context)],
            'created_by_user_id' => $actor?->id,
            'queued_at' => now(),
        ]);

        if (in_array($channel, ['sms', 'whatsapp'], true) && in_array($provider, ['log', '', 'null'], true)) {
            $row->fill([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => strtoupper($channel).' provider is not configured. Set '.($channel === 'whatsapp' ? 'WHATSAPP_PROVIDER' : 'SMS_PROVIDER').' and credentials before sending.',
            ])->save();

            return $row->fresh() ?? $row;
        }

        $ok = match ($channel) {
            'sms' => $this->sms->send($destination, $body, $context),
            'whatsapp' => $this->whatsapp->send($destination, $body, $context),
            default => false,
        };

        if ($ok) {
            $row->fill(['status' => 'sent', 'sent_at' => now()])->save();
        } else {
            $row->fill([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $row->error_message ?: 'Provider did not confirm delivery.',
            ])->save();
        }

        return $row->fresh() ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $sms = (string) config('communications.sms_provider');
        $whatsapp = (string) config('communications.whatsapp_provider');
        $twilioReady = filled(config('communications.twilio.account_sid')) && filled(config('communications.twilio.auth_token'));
        $metaReady = filled(config('communications.meta_whatsapp.token')) && filled(config('communications.meta_whatsapp.phone_number_id'));

        return [
            'email' => [
                'mailer' => config('mail.default'),
                'delivery_capable' => ! in_array(config('mail.default'), ['log', 'array'], true),
            ],
            'sms' => [
                'provider' => $sms,
                'configured' => $sms === 'twilio' && $twilioReady,
            ],
            'whatsapp' => [
                'provider' => $whatsapp,
                'configured' => ($whatsapp === 'twilio' && $twilioReady && filled(config('communications.twilio.whatsapp_from')))
                    || ($whatsapp === 'meta' && $metaReady),
                'note' => 'Bulk WhatsApp requires WhatsApp Business API credentials. wa.me links are not bulk delivery.',
            ],
        ];
    }
}
