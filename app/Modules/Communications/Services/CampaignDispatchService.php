<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Jobs\SendCampaignRecipientsJob;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Support\DeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Executes campaigns on the queue.
 *
 * - The audience is re-resolved at send time with the same resolver used for the preview, so consent
 *   and data changes since the preview are honoured.
 * - One bulk_email_recipients row per (campaign, channel, destination) keyed by idempotency_key; rows are
 *   claimed atomically (pending -> processing) so retries and duplicate workers never double-send.
 * - A campaign is completed only when every recipient has reached a terminal or provider-accepted state;
 *   delivery is tracked separately from provider callbacks.
 */
final class CampaignDispatchService implements ServiceContract
{
    public function __construct(
        private readonly RecipientAudienceService $audience,
        private readonly CommunicationConsentService $consent,
        private readonly CampaignMessageRenderer $renderer,
        private readonly OutboundMessageService $outbound,
        private readonly CommunicationSettingsService $settings,
    ) {}

    /**
     * Claim a queued campaign, build its recipient rows and enqueue send batches.
     */
    public function start(int $jobId): void
    {
        $claimed = BulkEmailJob::query()
            ->whereKey($jobId)
            ->where('status', BulkEmailJob::STATUS_QUEUED)
            ->update(['status' => BulkEmailJob::STATUS_PROCESSING, 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $job = BulkEmailJob::query()->findOrFail($jobId);
        $resolved = $this->audience->resolve(
            $job->recipient_filters ?? [],
            $job->channelList(),
            (string) ($job->message_type ?: 'promotional'),
            0,
            app(CampaignManualRecipientService::class)->emailsFor($job),
        );

        $now = now();
        foreach ($resolved['deliveries'] as $channel => $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                BulkEmailRecipient::query()->insertOrIgnore(array_map(fn (array $row): array => [
                    'bulk_email_job_id' => $job->id,
                    'channel' => $channel,
                    'email' => $channel === 'email' ? $row['destination'] : (string) $row['destination'],
                    'destination' => $row['destination'],
                    'name' => $row['name'] !== null ? mb_substr((string) $row['name'], 0, 255) : null,
                    'user_id' => $row['user_id'],
                    'person_id' => $row['person_id'],
                    'source' => $row['source'] ?? 'audience',
                    'status' => DeliveryStatus::PENDING,
                    'idempotency_key' => $this->key($job->id, $channel, (string) $row['destination']),
                    'metadata' => json_encode(['email' => $row['email'], 'first_name' => $row['first_name'] ?? null]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        }
        foreach ($resolved['exclusions'] as $channel => $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                BulkEmailRecipient::query()->insertOrIgnore(array_map(fn (array $row): array => [
                    'bulk_email_job_id' => $job->id,
                    'channel' => $channel,
                    'email' => (string) ($row['destination'] ?? ''),
                    'destination' => $row['destination'],
                    'name' => $row['name'] !== null ? mb_substr((string) $row['name'], 0, 255) : null,
                    'user_id' => $row['user_id'],
                    'person_id' => $row['person_id'],
                    'source' => $row['source'] ?? 'audience',
                    'status' => DeliveryStatus::SKIPPED,
                    'skip_reason' => $row['reason'],
                    'idempotency_key' => $this->key($job->id, $channel, 'skip:'.($row['destination'] ?? '').':'.($row['person_id'] ?? '').':'.($row['user_id'] ?? '').':'.($row['email'] ?? '').':'.($row['name'] ?? '')),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        }

        $job->forceFill([
            'estimated_count' => $resolved['summary']['total_messages'],
            'preview_snapshot' => array_merge($job->preview_snapshot ?? [], [
                'send_time' => [
                    'resolved_at' => now()->toIso8601String(),
                    'summary' => $resolved['summary'],
                    'channels' => $resolved['channels'],
                ],
            ]),
        ])->save();

        if ($resolved['summary']['total_messages'] === 0) {
            $job->forceFill([
                'status' => BulkEmailJob::STATUS_FAILED,
                'completed_at' => now(),
                'last_error' => 'No eligible recipients at send time.',
            ])->save();
            $this->refreshProgress($job->id);

            return;
        }

        $this->enqueuePending($job);
    }

    /**
     * Enqueue pending recipients in rate-limited batches.
     */
    public function enqueuePending(BulkEmailJob $job): int
    {
        $batchSize = max(1, (int) config('communications.campaigns.batch_size', 50));
        $queue = (string) config('communications.campaigns.queue', 'default');
        $batches = 0;

        foreach ($job->channelList() as $channel) {
            $rate = max(1, (int) config('communications.campaigns.rate_per_minute.'.$channel, 60));
            $secondsPerBatch = (int) ceil($batchSize / $rate * 60);
            $index = 0;
            BulkEmailRecipient::query()
                ->where('bulk_email_job_id', $job->id)
                ->where('channel', $channel)
                ->where('status', DeliveryStatus::PENDING)
                ->orderBy('id')
                ->select('id')
                ->chunkById($batchSize, function ($rows) use ($job, $channel, $queue, $secondsPerBatch, &$index, &$batches): void {
                    $pending = SendCampaignRecipientsJob::dispatch($job->id, $channel, $rows->pluck('id')->all())->onQueue($queue);
                    if ($index > 0) {
                        $pending->delay(now()->addSeconds($index * $secondsPerBatch));
                    }
                    $index++;
                    $batches++;
                });
        }

        return $batches;
    }

    /**
     * Send one batch. Returns true when transient failures left recipients pending for retry.
     *
     * @param  list<int>  $recipientIds
     */
    public function sendBatch(int $jobId, string $channel, array $recipientIds, int $attempt = 1): bool
    {
        $job = BulkEmailJob::query()->find($jobId);
        if ($job === null) {
            return false;
        }
        $maxAttempts = max(1, (int) config('communications.campaigns.max_attempts', 3));
        $needsRetry = false;

        foreach ($recipientIds as $recipientId) {
            if (BulkEmailJob::query()->whereKey($jobId)->value('status') === BulkEmailJob::STATUS_CANCELLED) {
                break;
            }

            $claimed = BulkEmailRecipient::query()
                ->whereKey($recipientId)
                ->where('status', DeliveryStatus::PENDING)
                ->update(['status' => DeliveryStatus::PROCESSING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if ($claimed === 0) {
                continue;
            }
            $recipient = BulkEmailRecipient::query()->findOrFail($recipientId);

            try {
                $retryable = $this->sendOne($job, $recipient);
            } catch (\Throwable $exception) {
                Log::warning('communications.campaign.recipient_exception', ['job' => $job->id, 'recipient' => $recipient->id, 'error' => class_basename($exception)]);
                $recipient->forceFill([
                    'status' => DeliveryStatus::FAILED,
                    'error_code' => 'exception',
                    'error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'failed_at' => now(),
                ])->save();
                $retryable = $recipient->attempts < $maxAttempts;
            }

            if ($retryable && $recipient->fresh()?->attempts < $maxAttempts) {
                // Provider never accepted this message, so a retry cannot duplicate it.
                $recipient->forceFill(['status' => DeliveryStatus::PENDING, 'failed_at' => null])->save();
                $needsRetry = true;
            }
        }

        $this->refreshProgress($jobId);

        return $needsRetry;
    }

    /**
     * Mark recipients stuck in processing (worker died mid-send) as failed without retrying, because the
     * provider may already have accepted them; then re-enqueue remaining pending recipients.
     *
     * @return array{requeued_batches: int, interrupted: int}
     */
    public function resume(BulkEmailJob $job, int $staleMinutes = 15): array
    {
        $interrupted = BulkEmailRecipient::query()
            ->where('bulk_email_job_id', $job->id)
            ->where('status', DeliveryStatus::PROCESSING)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->update([
                'status' => DeliveryStatus::FAILED,
                'error_code' => 'interrupted',
                'error_message' => 'Send was interrupted; not retried automatically to avoid a duplicate message.',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);

        if (in_array($job->status, [BulkEmailJob::STATUS_FAILED, BulkEmailJob::STATUS_PARTIAL], true)) {
            $job->forceFill(['status' => BulkEmailJob::STATUS_PROCESSING, 'completed_at' => null])->save();
        }
        $batches = $this->enqueuePending($job);
        $this->refreshProgress($job->id);

        return ['requeued_batches' => $batches, 'interrupted' => $interrupted];
    }

    /**
     * Recount from recipient rows and settle the campaign status.
     */
    public function refreshProgress(int $jobId): void
    {
        $counts = BulkEmailRecipient::query()
            ->where('bulk_email_job_id', $jobId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $accepted = ($counts[DeliveryStatus::ACCEPTED] ?? 0) + ($counts[DeliveryStatus::LEGACY_SENT] ?? 0);
        $delivered = ($counts[DeliveryStatus::DELIVERED] ?? 0) + ($counts[DeliveryStatus::READ] ?? 0);
        $failed = ($counts[DeliveryStatus::FAILED] ?? 0) + ($counts[DeliveryStatus::BOUNCED] ?? 0);
        $skipped = $counts[DeliveryStatus::SKIPPED] ?? 0;
        $open = ($counts[DeliveryStatus::PENDING] ?? 0) + ($counts[DeliveryStatus::PROCESSING] ?? 0);

        $job = BulkEmailJob::query()->find($jobId);
        if ($job === null) {
            return;
        }
        $job->forceFill([
            'accepted_count' => $accepted,
            'delivered_count' => $delivered,
            'sent_count' => $accepted + $delivered,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
        ]);

        if ($open === 0 && in_array($job->status, [BulkEmailJob::STATUS_PROCESSING, 'sending'], true)) {
            $handed = $accepted + $delivered;
            $job->status = match (true) {
                $handed > 0 && $failed === 0 => BulkEmailJob::STATUS_COMPLETED,
                $handed > 0 => BulkEmailJob::STATUS_PARTIAL,
                default => BulkEmailJob::STATUS_FAILED,
            };
            $job->completed_at = now();
            if ($job->status === BulkEmailJob::STATUS_FAILED && $job->last_error === null) {
                $job->last_error = 'No message was accepted by a provider.';
            }
        }
        $job->save();
    }

    /**
     * @return bool true when the failure is transient and the recipient may be retried
     */
    private function sendOne(BulkEmailJob $job, BulkEmailRecipient $recipient): bool
    {
        $channel = (string) $recipient->channel;
        $destination = (string) ($recipient->destination ?: $recipient->email);
        $messageType = (string) ($job->message_type ?: 'promotional');

        $decision = $this->consent->evaluate($channel, $destination, $messageType);
        if (! $decision['allowed']) {
            $recipient->forceFill(['status' => DeliveryStatus::SKIPPED, 'skip_reason' => $decision['reason']])->save();

            return false;
        }

        $meta = is_array($recipient->metadata) ? $recipient->metadata : [];
        $vars = $this->renderer->variables([
            'name' => $recipient->name,
            'first_name' => $meta['first_name'] ?? null,
            'email' => $channel === 'email' ? $destination : ($meta['email'] ?? null),
            'destination' => $destination,
        ], $job, $channel);

        if ($channel === 'email') {
            return $this->sendEmail($job, $recipient, $destination, $vars);
        }

        $template = $channel === 'whatsapp' ? $this->renderer->whatsappTemplate($job, $vars) : null;
        if ($channel === 'whatsapp' && $template === null) {
            $recipient->forceFill([
                'status' => DeliveryStatus::FAILED,
                'error_code' => 'template_required',
                'error_message' => 'WhatsApp campaigns require an approved template.',
                'failed_at' => now(),
            ])->save();

            return false;
        }

        $row = $this->outbound->send($channel, $destination, $this->renderer->text($job, $vars), [
            'subject' => $job->subject,
            'message_type' => $messageType,
            'module' => $job->module,
            'whatsapp_template' => $template,
            'idempotency_key' => (string) $recipient->idempotency_key,
            'bulk_email_recipient_id' => $recipient->id,
            'skip_consent_check' => true,
        ], $job->creator);

        $status = (string) $row->status;
        $recipient->forceFill([
            'outbound_message_id' => $row->id,
            'provider' => $row->provider,
            'provider_message_id' => $row->provider_message_id,
            'segments' => $row->segments,
            'cost' => $row->cost,
            'currency' => $row->currency,
        ]);

        if (in_array($status, DeliveryStatus::acceptedLike(), true)) {
            $recipient->forceFill([
                'status' => $status === DeliveryStatus::LEGACY_SENT ? DeliveryStatus::ACCEPTED : $status,
                'accepted_at' => $row->sent_at ?? now(),
                'sent_at' => $row->sent_at ?? now(),
                'delivered_at' => $row->delivered_at,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            app(DeliveryStatusService::class)->applyPendingEventsFor((string) $row->provider, (string) $row->provider_message_id);

            return false;
        }

        $recipient->forceFill([
            'status' => $status === DeliveryStatus::SKIPPED ? DeliveryStatus::SKIPPED : DeliveryStatus::FAILED,
            'skip_reason' => $status === DeliveryStatus::SKIPPED ? $row->error_code : null,
            'error_code' => $row->error_code,
            'error_message' => $row->error_message,
            'failed_at' => $status === DeliveryStatus::SKIPPED ? null : now(),
        ])->save();

        $retryable = (bool) (($row->metadata ?? [])['retryable'] ?? false);
        if ($retryable) {
            // Allow the retry to create a fresh provider attempt under the same idempotency key.
            $row->forceFill(['status' => DeliveryStatus::QUEUED])->save();
        }

        return $retryable;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function sendEmail(BulkEmailJob $job, BulkEmailRecipient $recipient, string $destination, array $vars): bool
    {
        $mailer = (string) config('mail.default');
        if ($mailer === 'log') {
            $recipient->forceFill([
                'status' => DeliveryStatus::SKIPPED,
                'skip_reason' => 'mailer_not_delivering',
                'error_message' => 'MAIL_MAILER=log writes messages to the log; nothing was delivered.',
                'provider' => 'log',
            ])->save();

            return false;
        }

        $content = $this->renderer->email($job, $vars);
        $settings = $this->settings->get();
        try {
            $sent = Mail::to($destination, $recipient->name)->send(new CommunicationMailable(
                mailSubject: $content['subject'],
                htmlBody: $content['html'],
                textBody: $content['text'],
                replyToEmail: filter_var($settings->reply_to_email, FILTER_VALIDATE_EMAIL) ? $settings->reply_to_email : null,
                replyToName: $settings->reply_to_name,
                fromName: $job->from_name ?: $settings->from_name,
                listUnsubscribeUrl: $vars['unsubscribe_url'] !== '' ? $vars['unsubscribe_url'] : null,
            ));
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $exception) {
            $recipient->forceFill([
                'status' => DeliveryStatus::FAILED,
                'error_code' => 'smtp_error',
                'error_message' => mb_substr(preg_replace('/(password|secret|token)\S*/i', '[redacted]', $exception->getMessage()) ?? '', 0, 500),
                'failed_at' => now(),
                'provider' => $mailer,
            ])->save();

            return true;
        }

        $messageId = null;
        if (is_object($sent) && method_exists($sent, 'getMessageId')) {
            $messageId = $sent->getMessageId();
        }
        $recipient->forceFill([
            'status' => DeliveryStatus::ACCEPTED,
            'provider' => $mailer,
            'provider_message_id' => is_string($messageId) && $messageId !== '' ? mb_substr($messageId, 0, 191) : null,
            'accepted_at' => now(),
            'sent_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        return false;
    }

    private function key(int $jobId, string $channel, string $destination): string
    {
        return 'campaign:'.$jobId.':'.$channel.':'.hash('sha256', strtolower($destination));
    }
}
