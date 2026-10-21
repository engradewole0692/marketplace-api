<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Modules\Communications\Support\ProviderSendResult;
use App\Modules\Communications\Support\SmsSegmentCalculator;

/**
 * SMS / WhatsApp sends with provider evidence. A row is "accepted" only when the provider returned a
 * message id; "delivered"/"read" are set later from signed provider callbacks.
 */
final class OutboundMessageService implements ServiceContract
{
    public function __construct(
        private readonly SmsNotifierContract $sms,
        private readonly WhatsAppNotifierContract $whatsapp,
        private readonly PhoneNumberService $phones,
        private readonly SmsSegmentCalculator $segments,
    ) {}

    /**
     * @param  array<string, mixed>  $context  keys: subject, idempotency_key, message_type, whatsapp_template,
     *                                         bulk_email_recipient_id, region, skip_consent_check
     */
    public function send(string $channel, string $to, string $body, array $context = [], ?User $actor = null): CommunicationOutboundMessage
    {
        $channel = strtolower($channel);
        $idempotencyKey = isset($context['idempotency_key']) ? (string) $context['idempotency_key'] : null;
        $existing = null;
        if ($idempotencyKey !== null) {
            $existing = CommunicationOutboundMessage::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null && $existing->status !== DeliveryStatus::QUEUED) {
                return $existing;
            }
        }

        $provider = $channel === 'whatsapp' ? $this->whatsapp->providerName() : $this->sms->providerName();
        $normalized = $this->phones->normalize($to, isset($context['region']) ? (string) $context['region'] : null);
        $destination = $normalized['e164'] ?? $to;
        $template = is_array($context['whatsapp_template'] ?? null) ? $context['whatsapp_template'] : null;
        $segmentInfo = $channel === 'sms' ? $this->segments->calculate($body) : null;

        $row = $existing ?? CommunicationOutboundMessage::query()->create([
            'channel' => $channel,
            'to' => $destination,
            'status' => DeliveryStatus::QUEUED,
            'provider' => $provider,
            'body' => $template ? '[template '.$template['name'].'] '.implode(' | ', array_map('strval', $template['parameters'] ?? [])) : $body,
            'subject' => $context['subject'] ?? null,
            'metadata' => array_filter([
                'message_type' => $context['message_type'] ?? null,
                'module' => $context['module'] ?? null,
                'template' => $template ? ['name' => $template['name'], 'language' => $template['language']] : null,
                'encoding' => $segmentInfo['encoding'] ?? null,
            ]),
            'segments' => $segmentInfo['segments'] ?? null,
            'created_by_user_id' => $actor?->id,
            'queued_at' => now(),
            'idempotency_key' => $idempotencyKey,
            'bulk_email_recipient_id' => $context['bulk_email_recipient_id'] ?? null,
        ]);

        if (! $normalized['valid']) {
            return $this->fail($row, (string) ($normalized['reason'] ?? 'invalid_phone'), 'Destination is not a valid international phone number.');
        }

        if (empty($context['skip_consent_check'])) {
            $decision = app(CommunicationConsentService::class)->evaluate($channel, $destination, (string) ($context['message_type'] ?? 'transactional'));
            if (! $decision['allowed']) {
                $row->fill([
                    'status' => DeliveryStatus::SKIPPED,
                    'error_code' => $decision['reason'],
                    'error_message' => 'Not sent: recipient consent/suppression rules exclude this destination ('.$decision['reason'].').',
                ])->save();

                return $row->fresh() ?? $row;
            }
        }

        if ($channel === 'whatsapp') {
            $result = $template !== null
                ? $this->whatsapp->dispatchTemplate($destination, $template, $context)
                : $this->whatsapp->dispatch($destination, $body, $context);
        } elseif ($channel === 'sms') {
            $result = $this->sms->dispatch($destination, $body, $context);
        } else {
            $result = ProviderSendResult::failed($provider, 'unsupported_channel', 'Unsupported channel.');
        }

        return $this->applyResult($row, $result);
    }

    private function applyResult(CommunicationOutboundMessage $row, ProviderSendResult $result): CommunicationOutboundMessage
    {
        if ($result->accepted) {
            $estimate = $this->estimatedCost($row);
            $row->fill([
                'status' => DeliveryStatus::ACCEPTED,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
                'cost' => $result->cost ?? $estimate,
                'currency' => $result->currency ?? ($estimate !== null ? (string) config('communications.costs.currency') : null),
                'metadata' => array_merge(is_array($row->metadata) ? $row->metadata : [], array_filter([
                    'provider_status' => $result->providerStatus,
                    'cost_source' => $result->cost !== null ? 'provider' : ($estimate !== null ? 'estimate' : null),
                ])),
            ])->save();

            app(DeliveryStatusService::class)->applyPendingEventsFor($result->provider, (string) $result->providerMessageId);

            return $row->fresh() ?? $row;
        }

        $row->setAttribute('provider', $result->provider);

        return $this->fail($row, (string) $result->errorCode, (string) $result->errorMessage, $result->retryable);
    }

    private function fail(CommunicationOutboundMessage $row, string $code, string $message, bool $retryable = false): CommunicationOutboundMessage
    {
        $row->fill([
            'status' => DeliveryStatus::FAILED,
            'failed_at' => now(),
            'error_code' => mb_substr($code, 0, 80),
            'error_message' => mb_substr($message, 0, 500),
            'metadata' => array_merge(is_array($row->metadata) ? $row->metadata : [], ['retryable' => $retryable]),
        ])->save();

        return $row->fresh() ?? $row;
    }

    private function estimatedCost(CommunicationOutboundMessage $row): ?float
    {
        if ($row->channel === 'sms' && is_numeric(config('communications.costs.sms_per_segment'))) {
            return round((float) config('communications.costs.sms_per_segment') * max(1, (int) $row->segments), 4);
        }
        if ($row->channel === 'whatsapp' && is_numeric(config('communications.costs.whatsapp_per_message'))) {
            return (float) config('communications.costs.whatsapp_per_message');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'email' => [
                'mailer' => config('mail.default'),
                'delivery_capable' => ! in_array(config('mail.default'), ['log', 'array'], true),
            ],
            'sms' => [
                'provider' => $this->sms->providerName(),
                'configured' => app(ProviderConfigurationService::class)->channelConfigured('sms'),
            ],
            'whatsapp' => [
                'provider' => $this->whatsapp->providerName(),
                'configured' => app(ProviderConfigurationService::class)->channelConfigured('whatsapp'),
                'note' => 'Bulk WhatsApp requires the WhatsApp Business Platform and approved templates. wa.me links are not bulk delivery.',
            ],
        ];
    }
}
