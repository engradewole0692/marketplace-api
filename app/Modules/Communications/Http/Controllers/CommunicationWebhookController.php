<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Services\CommunicationConsentService;
use App\Modules\Communications\Services\DeliveryStatusService;
use App\Modules\Communications\Support\CommunicationWebhookUrls;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Support\PhoneNumberService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Provider delivery callbacks and inbound opt-out replies. Every request must carry a valid provider
 * signature; unsigned or mis-signed requests are rejected without side effects.
 */
final class CommunicationWebhookController extends Controller
{
    private const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    private const START_WORDS = ['START', 'UNSTOP', 'YES'];

    public function __construct(
        private readonly DeliveryStatusService $deliveries,
        private readonly CommunicationConsentService $consent,
        private readonly PhoneNumberService $phones,
    ) {}

    public function twilio(Request $request): Response
    {
        $token = (string) config('communications.twilio.auth_token');
        if ($token === '' || ! $this->validTwilioSignature($request, $token)) {
            Log::warning('communications.webhook.twilio.invalid_signature');

            return response('Invalid signature', 403);
        }

        $sid = (string) $request->input('MessageSid', $request->input('SmsSid', ''));
        $status = strtolower((string) $request->input('MessageStatus', $request->input('SmsStatus', '')));
        $from = (string) $request->input('From', '');
        $channel = str_starts_with(strtolower($from), 'whatsapp:') || str_starts_with(strtolower((string) $request->input('To', '')), 'whatsapp:') ? 'whatsapp' : 'sms';

        if ($status === 'received' || ($request->filled('Body') && ! $request->filled('MessageStatus'))) {
            $this->inboundKeyword($channel, $from, (string) $request->input('Body', ''), 'twilio');

            return response('', 204);
        }

        $mapped = match ($status) {
            'sent' => DeliveryStatus::ACCEPTED,
            'delivered' => DeliveryStatus::DELIVERED,
            'read' => DeliveryStatus::READ,
            'undelivered', 'failed', 'canceled' => DeliveryStatus::FAILED,
            default => null,
        };
        if ($sid !== '' && $mapped !== null) {
            $errorCode = $request->filled('ErrorCode') ? (string) $request->input('ErrorCode') : null;
            $this->deliveries->record(
                'twilio',
                $channel,
                $sid,
                $mapped,
                $status,
                $errorCode,
                $errorCode ? 'Twilio error '.$errorCode : null,
                null,
                $sid.':'.$status.':'.($errorCode ?? ''),
                ['status' => $status, 'error_code' => $errorCode],
            );
        }

        return response('', 204);
    }

    public function termii(Request $request): Response
    {
        $secret = (string) config('communications.termii.secret_key');
        $signature = (string) $request->header('X-Termii-Signature', '');
        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            Log::warning('communications.webhook.termii.invalid_signature');

            return response('Invalid signature', 403);
        }

        $messageId = (string) ($request->input('message_id') ?? $request->input('id') ?? '');
        $raw = strtolower(trim((string) $request->input('status', '')));
        $mapped = match (true) {
            $raw === 'delivered' => DeliveryStatus::DELIVERED,
            in_array($raw, ['sent', 'message sent'], true) => DeliveryStatus::ACCEPTED,
            str_contains($raw, 'fail') || str_contains($raw, 'reject') || str_contains($raw, 'expire') || str_contains($raw, 'dnd') || str_contains($raw, 'undeliver') => DeliveryStatus::FAILED,
            default => null,
        };
        if ($messageId !== '' && $mapped !== null) {
            $code = $mapped === DeliveryStatus::FAILED ? (str_contains($raw, 'dnd') ? 'dnd_active' : str_replace(' ', '_', $raw)) : null;
            $this->deliveries->record(
                'termii',
                'sms',
                $messageId,
                $mapped,
                $raw,
                $code,
                $mapped === DeliveryStatus::FAILED ? 'Termii reported: '.$request->input('status') : null,
                $this->parseTime($request->input('sent_at')),
                $messageId.':'.$raw,
                ['status' => $raw, 'cost' => $request->input('cost'), 'channel' => $request->input('channel')],
            );
        }

        return response('', 200);
    }

    public function metaVerify(Request $request): Response
    {
        $expected = (string) config('communications.meta_whatsapp.verify_token');
        if ($expected !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($expected, (string) $request->query('hub_verify_token', ''))) {
            return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function meta(Request $request): Response
    {
        $secret = (string) config('communications.meta_whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            Log::warning('communications.webhook.meta.invalid_signature');

            return response('Invalid signature', 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $raw = strtolower((string) ($status['status'] ?? ''));
                    $mapped = match ($raw) {
                        'sent' => DeliveryStatus::ACCEPTED,
                        'delivered' => DeliveryStatus::DELIVERED,
                        'read' => DeliveryStatus::READ,
                        'failed' => DeliveryStatus::FAILED,
                        default => null,
                    };
                    $id = (string) ($status['id'] ?? '');
                    if ($id === '' || $mapped === null) {
                        continue;
                    }
                    $error = (array) (($status['errors'] ?? [])[0] ?? []);
                    $code = isset($error['code']) ? (string) $error['code'] : null;
                    $this->deliveries->record(
                        'meta',
                        'whatsapp',
                        $id,
                        $mapped,
                        $raw,
                        $code,
                        $code ? (string) ($error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? 'WhatsApp error') : null,
                        isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : null,
                        $id.':'.$raw.':'.($code ?? ''),
                        ['status' => $raw, 'error_code' => $code, 'pricing_category' => $status['pricing']['category'] ?? null],
                    );
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (($message['type'] ?? '') === 'text') {
                        $this->inboundKeyword('whatsapp', '+'.ltrim((string) ($message['from'] ?? ''), '+'), (string) ($message['text']['body'] ?? ''), 'meta');
                    }
                }
            }
        }

        return response('', 200);
    }

    /**
     * Signed one-click unsubscribe for promotional email.
     */
    public function unsubscribe(Request $request): Response
    {
        if (! $request->hasValidSignature()) {
            return response($this->page('This unsubscribe link is invalid or has expired.'), 403)->header('Content-Type', 'text/html');
        }
        $channel = (string) $request->query('channel', 'email');
        $address = base64_decode(strtr((string) $request->query('address', ''), '-_', '+/'), true);
        if ($channel !== 'email' || ! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return response($this->page('This unsubscribe link is invalid.'), 422)->header('Content-Type', 'text/html');
        }

        $this->consent->record('email', $address, CommunicationConsent::OPTED_OUT, 'promotional', 'unsubscribe_link', 'Recipient used the unsubscribe link.');

        return response($this->page('You have been unsubscribed from Marketplace Ministers announcements. You will still receive messages about registrations and services you request.'), 200)
            ->header('Content-Type', 'text/html');
    }

    private function inboundKeyword(string $channel, string $from, string $body, string $provider): void
    {
        $word = strtoupper(trim($body));
        $destination = $this->phones->e164(preg_replace('/^whatsapp:/i', '', $from));
        if ($destination === null) {
            return;
        }
        if (in_array($word, self::STOP_WORDS, true)) {
            $this->consent->record($channel, $destination, CommunicationConsent::OPTED_OUT, 'all', 'reply_stop_'.$provider, 'Recipient replied '.$word);
        } elseif (in_array($word, self::START_WORDS, true)) {
            $this->consent->record($channel, $destination, CommunicationConsent::OPTED_IN, 'all', 'reply_start_'.$provider, 'Recipient replied '.$word);
        }
    }

    private function validTwilioSignature(Request $request, string $token): bool
    {
        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }
        $params = $request->request->all();
        ksort($params);
        $data = '';
        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }
        foreach (array_unique([CommunicationWebhookUrls::for('twilio'), $request->fullUrl()]) as $url) {
            if (hash_equals(base64_encode(hash_hmac('sha1', $url.$data, $token, true)), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value, 'Africa/Lagos');
        } catch (\Throwable) {
            return null;
        }
    }

    private function page(string $message): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Marketplace Ministers</title></head>'
            .'<body style="font-family:Georgia,serif;max-width:560px;margin:64px auto;padding:0 24px;color:#1c1917;line-height:1.6">'
            .'<p style="letter-spacing:.18em;text-transform:uppercase;font-size:11px;color:#a16207">Marketplace Ministers</p>'
            .'<p>'.e($message).'</p></body></html>';
    }
}
