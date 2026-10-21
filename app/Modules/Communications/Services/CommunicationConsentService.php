<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Models\CommunicationConsentEvent;
use Illuminate\Support\Facades\DB;

/**
 * Per-channel consent and suppression.
 *
 * Rules applied before every send (preview and send time):
 * - suppressed (hard bounce, complaint, carrier/provider block) excludes all message types;
 * - opted_out with purpose "all" excludes all message types;
 * - opted_out with purpose "promotional" (incl. newsletter unsubscribes for email) excludes promotional messages;
 * - WhatsApp requires a recorded opt-in when configured (Meta business-initiated policy);
 * - promotional SMS requires opt-in only when configured.
 * There is no override for promotional opt-outs.
 */
final class CommunicationConsentService implements ServiceContract
{
    /**
     * @param  list<string>  $destinations
     * @return array<string, array{allowed: bool, reason: ?string}>
     */
    public function evaluateMany(string $channel, array $destinations, string $messageType): array
    {
        $destinations = array_values(array_unique(array_filter(array_map(fn ($d) => $this->key($channel, (string) $d), $destinations))));
        if ($destinations === []) {
            return [];
        }

        $records = [];
        foreach (array_chunk($destinations, 500) as $chunk) {
            CommunicationConsent::query()
                ->where('channel', $channel)
                ->whereIn('destination', $chunk)
                ->get(['destination', 'purpose', 'status'])
                ->each(function (CommunicationConsent $row) use (&$records): void {
                    $records[$row->destination][$row->purpose] = $row->status;
                });
        }

        $newsletterOptOuts = $channel === 'email' ? $this->newsletterOptOuts($destinations) : [];

        $result = [];
        foreach ($destinations as $destination) {
            $result[$destination] = $this->decide($channel, $messageType, $records[$destination] ?? [], isset($newsletterOptOuts[$destination]));
        }

        return $result;
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function evaluate(string $channel, string $destination, string $messageType): array
    {
        $key = $this->key($channel, $destination);

        return $this->evaluateMany($channel, [$key], $messageType)[$key] ?? ['allowed' => false, 'reason' => 'missing_destination'];
    }

    public function record(
        string $channel,
        string $destination,
        string $status,
        string $purpose,
        string $source,
        ?string $reason = null,
        ?User $actor = null,
        ?int $personId = null,
        ?int $userId = null,
        array $metadata = [],
    ): CommunicationConsent {
        $destination = $this->key($channel, $destination);
        $purpose = in_array($purpose, ['promotional', 'all'], true) ? $purpose : 'promotional';

        return DB::transaction(function () use ($channel, $destination, $status, $purpose, $source, $reason, $actor, $personId, $userId, $metadata): CommunicationConsent {
            $consent = CommunicationConsent::query()
                ->where('channel', $channel)
                ->where('destination', $destination)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();
            $from = $consent?->status;

            $consent ??= new CommunicationConsent([
                'channel' => $channel,
                'destination' => $destination,
                'purpose' => $purpose,
            ]);
            $consent->fill([
                'status' => $status,
                'source' => $source,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'person_id' => $personId ?? $consent->person_id,
                'user_id' => $userId ?? $consent->user_id,
                'recorded_by_user_id' => $actor?->id,
                'metadata' => array_merge(is_array($consent->metadata) ? $consent->metadata : [], $metadata),
            ]);
            match ($status) {
                CommunicationConsent::OPTED_IN => $consent->opted_in_at = now(),
                CommunicationConsent::OPTED_OUT => $consent->opted_out_at = now(),
                CommunicationConsent::SUPPRESSED => $consent->suppressed_at = now(),
                default => null,
            };
            $consent->save();

            CommunicationConsentEvent::query()->create([
                'consent_id' => $consent->id,
                'action' => $from === null ? 'created' : 'changed',
                'from_status' => $from,
                'to_status' => $status,
                'source' => $source,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'actor_user_id' => $actor?->id,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            return $consent;
        });
    }

    public function key(string $channel, string $destination): string
    {
        $destination = trim($destination);

        return $channel === 'email' ? strtolower($destination) : $destination;
    }

    /**
     * @param  array<string, string>  $records  purpose => status
     * @return array{allowed: bool, reason: ?string}
     */
    private function decide(string $channel, string $messageType, array $records, bool $newsletterOptOut): array
    {
        foreach ($records as $status) {
            if ($status === CommunicationConsent::SUPPRESSED) {
                return ['allowed' => false, 'reason' => 'suppressed'];
            }
        }
        if (($records['all'] ?? null) === CommunicationConsent::OPTED_OUT) {
            return ['allowed' => false, 'reason' => 'opted_out'];
        }

        $promotional = $messageType !== 'transactional';
        if ($promotional && (($records['promotional'] ?? null) === CommunicationConsent::OPTED_OUT || $newsletterOptOut)) {
            return ['allowed' => false, 'reason' => 'opted_out'];
        }

        $optedIn = in_array(CommunicationConsent::OPTED_IN, $records, true);
        if ($channel === 'whatsapp' && config('communications.consent.whatsapp_requires_opt_in', true) && ! $optedIn) {
            return ['allowed' => false, 'reason' => 'no_opt_in'];
        }
        if ($channel === 'sms' && $promotional && config('communications.consent.sms_promotional_requires_opt_in', false) && ! $optedIn) {
            return ['allowed' => false, 'reason' => 'no_opt_in'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Newsletter unsubscribes submitted through the public form count as email promotional opt-outs
     * unless a later newsletter subscription exists for the same address.
     *
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function newsletterOptOuts(array $emails): array
    {
        $optOuts = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            $latest = [];
            CmsFormSubmission::query()
                ->where('type', FormSubmissionType::Newsletter->value)
                ->whereIn('submitter_email', $chunk)
                ->orderBy('id')
                ->get(['submitter_email', 'payload'])
                ->each(function (CmsFormSubmission $submission) use (&$latest): void {
                    $payload = is_array($submission->payload) ? $submission->payload : [];
                    $latest[strtolower((string) $submission->submitter_email)] = ($payload['action'] ?? 'subscribe') === 'unsubscribe';
                });
            foreach ($latest as $email => $unsubscribed) {
                if ($unsubscribed) {
                    $optOuts[$email] = true;
                }
            }
        }

        return $optOuts;
    }
}
