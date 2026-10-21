<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Models\CommunicationDeliveryEvent;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use App\Modules\Communications\Support\DeliveryStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Applies provider delivery callbacks. Events are stored once (replays ignored), statuses only move
 * forward (delivered/read are never downgraded), and callbacks that arrive before the send was recorded
 * are kept unapplied and reconciled later.
 */
final class DeliveryStatusService implements ServiceContract
{
    /** Provider error codes that mean the recipient asked to stop. */
    private const OPT_OUT_CODES = [
        'twilio' => ['21610' => 'all'],
        'meta' => ['131050' => 'promotional'],
    ];

    /**
     * @param  array<string, mixed>  $payload  sanitized; never includes message bodies or credentials
     */
    public function record(
        string $provider,
        string $channel,
        string $providerMessageId,
        string $status,
        ?string $rawStatus,
        ?string $errorCode,
        ?string $errorMessage,
        ?Carbon $occurredAt,
        string $eventKey,
        array $payload = [],
    ): CommunicationDeliveryEvent {
        try {
            $event = CommunicationDeliveryEvent::query()->create([
                'provider' => $provider,
                'channel' => $channel,
                'provider_message_id' => $providerMessageId,
                'event_key' => mb_substr($eventKey, 0, 191),
                'status' => $status,
                'raw_status' => $rawStatus !== null ? mb_substr($rawStatus, 0, 80) : null,
                'error_code' => $errorCode !== null && $errorCode !== '' ? mb_substr($errorCode, 0, 80) : null,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
                'occurred_at' => $occurredAt ?? now(),
                'payload' => $payload,
            ]);
        } catch (QueryException) {
            // Duplicate callback (provider retry). Already recorded and applied once.
            return CommunicationDeliveryEvent::query()
                ->where('provider', $provider)
                ->where('event_key', mb_substr($eventKey, 0, 191))
                ->firstOrFail();
        }

        $this->apply($event);

        return $event->fresh() ?? $event;
    }

    public function apply(CommunicationDeliveryEvent $event): bool
    {
        if ($event->applied) {
            return true;
        }

        $outbound = CommunicationOutboundMessage::query()
            ->where('provider', $event->provider)
            ->where('provider_message_id', $event->provider_message_id)
            ->first();
        $recipient = BulkEmailRecipient::query()
            ->where('provider', $event->provider)
            ->where('provider_message_id', $event->provider_message_id)
            ->first();

        if ($outbound === null && $recipient === null) {
            return false;
        }

        $at = $event->occurred_at ?? now();
        if ($outbound !== null) {
            $this->transition($outbound, $event, $at, 'sent_at');
        }
        if ($recipient !== null) {
            $this->transition($recipient, $event, $at, 'accepted_at');
            app(CampaignDispatchService::class)->refreshProgress((int) $recipient->bulk_email_job_id);
        }

        $this->applyOptOut($event, $outbound?->to ?? $recipient?->destination);

        $event->forceFill(['applied' => true])->save();

        return true;
    }

    public function applyPendingEventsFor(string $provider, string $providerMessageId): void
    {
        if ($providerMessageId === '') {
            return;
        }
        CommunicationDeliveryEvent::query()
            ->where('provider', $provider)
            ->where('provider_message_id', $providerMessageId)
            ->where('applied', false)
            ->orderBy('occurred_at')
            ->get()
            ->each(fn (CommunicationDeliveryEvent $event) => $this->apply($event));
    }

    /**
     * Apply callbacks that arrived before their message was recorded.
     */
    public function reconcile(int $limit = 500): int
    {
        $applied = 0;
        CommunicationDeliveryEvent::query()
            ->where('applied', false)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (CommunicationDeliveryEvent $event) use (&$applied): void {
                if ($this->apply($event)) {
                    $applied++;
                }
            });

        return $applied;
    }

    private function transition(CommunicationOutboundMessage|BulkEmailRecipient $model, CommunicationDeliveryEvent $event, Carbon $at, string $acceptedColumn): void
    {
        $model->setAttribute('last_event_at', $at);
        if (! DeliveryStatus::canTransition((string) $model->status, $event->status)) {
            $model->save();

            return;
        }

        $model->setAttribute('status', $event->status);
        match ($event->status) {
            DeliveryStatus::ACCEPTED => $model->setAttribute($acceptedColumn, $model->getAttribute($acceptedColumn) ?? $at),
            DeliveryStatus::DELIVERED => $model->setAttribute('delivered_at', $at),
            DeliveryStatus::READ => $model->forceFill(['read_at' => $at, 'delivered_at' => $model->getAttribute('delivered_at') ?? $at]),
            DeliveryStatus::FAILED, DeliveryStatus::BOUNCED => $model->forceFill([
                'failed_at' => $at,
                'error_code' => $event->error_code,
                'error_message' => $event->error_message ?? ('Provider reported '.$event->raw_status),
            ]),
            default => null,
        };
        $model->save();
    }

    private function applyOptOut(CommunicationDeliveryEvent $event, ?string $destination): void
    {
        if ($destination === null || $event->error_code === null) {
            return;
        }
        $purpose = self::OPT_OUT_CODES[$event->provider][$event->error_code] ?? null;
        if ($purpose !== null) {
            app(CommunicationConsentService::class)->record(
                $event->channel,
                $destination,
                CommunicationConsent::OPTED_OUT,
                $purpose,
                'provider_error_'.$event->provider,
                'Provider reported error '.$event->error_code,
            );
        }
        if ($event->status === DeliveryStatus::BOUNCED) {
            app(CommunicationConsentService::class)->record(
                $event->channel,
                $destination,
                CommunicationConsent::SUPPRESSED,
                'all',
                'bounce_'.$event->provider,
                $event->error_message,
            );
        }
    }
}
