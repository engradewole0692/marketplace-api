<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Models\CommunicationWhatsAppTemplate;
use App\Modules\Communications\Support\CommunicationWebhookUrls;

/**
 * Provider Configuration Center. Credentials live in the environment only; this service reports masked
 * identifiers, missing settings and verification state. "verified" requires a successful real test send
 * recorded against the current configuration fingerprint.
 */
final class ProviderConfigurationService implements ServiceContract
{
    public const NOT_CONFIGURED = 'not_configured';

    public const MISSING_SETTINGS = 'missing_settings';

    public const UNVERIFIED = 'configured_unverified';

    public const VERIFIED = 'verified';

    public const PROVIDER_ERROR = 'provider_error';

    public function __construct(
        private readonly CommunicationSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'email' => $this->channel('email'),
            'sms' => $this->channel('sms'),
            'whatsapp' => $this->channel('whatsapp'),
            'queue' => app(QueueHealthService::class)->snapshot(),
            'secrets_policy' => 'Credentials are read from server environment variables only and are never returned by this API.',
        ];
    }

    public function channelConfigured(string $channel): bool
    {
        $definition = $this->definition($channel);

        return $definition['provider'] !== 'log' && $definition['provider'] !== '' && $this->missing($definition) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function channel(string $channel): array
    {
        $definition = $this->definition($channel);
        $missing = $this->missing($definition);
        $verification = $this->verification($channel);
        $fingerprint = $this->fingerprint($channel);
        $current = ($verification['fingerprint'] ?? null) === $fingerprint;

        $status = match (true) {
            in_array($definition['provider'], ['log', '', 'null', 'array'], true) => self::NOT_CONFIGURED,
            $missing !== [] => self::MISSING_SETTINGS,
            $current && ($verification['status'] ?? null) === 'error' => self::PROVIDER_ERROR,
            $current && ($verification['status'] ?? null) === 'verified' => self::VERIFIED,
            default => self::UNVERIFIED,
        };

        return [
            'channel' => $channel,
            'provider' => $definition['provider'],
            'status' => $status,
            'missing' => $missing,
            'identifiers' => $definition['identifiers'],
            'webhook_urls' => $definition['webhooks'],
            'setup' => $definition['setup'],
            'last_verification' => $current ? $verification : null,
            'last_credential_check' => $current ? ($verification['credential_check'] ?? null) : null,
            'extra' => $definition['extra'] ?? [],
        ];
    }

    /**
     * Credential check (no message sent). Never upgrades status to verified on its own.
     *
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function checkCredentials(string $channel): array
    {
        $result = match ($channel) {
            'sms' => app(SmsNotifierContract::class)->checkCredentials(),
            'whatsapp' => app(WhatsAppNotifierContract::class)->checkCredentials(),
            default => $this->checkMailer(),
        };

        $verification = $this->verification($channel);
        if (($verification['fingerprint'] ?? null) !== $this->fingerprint($channel)) {
            $verification = [];
        }
        $verification['fingerprint'] = $this->fingerprint($channel);
        $verification['credential_check'] = [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'at' => now()->toIso8601String(),
        ];
        if (! $result['ok']) {
            $verification['status'] = 'error';
            $verification['message'] = $result['message'];
            $verification['at'] = now()->toIso8601String();
        }
        $this->storeVerification($channel, $verification);

        return $result;
    }

    /**
     * Record the outcome of a real test send.
     */
    public function recordTestResult(string $channel, bool $accepted, string $message, ?string $reference = null): void
    {
        $verification = $this->verification($channel);
        if (($verification['fingerprint'] ?? null) !== $this->fingerprint($channel)) {
            $verification = [];
        }
        $this->storeVerification($channel, array_merge($verification, [
            'status' => $accepted ? 'verified' : 'error',
            'message' => $message,
            'reference' => $reference,
            'at' => now()->toIso8601String(),
            'fingerprint' => $this->fingerprint($channel),
            'method' => 'test_send',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function verification(string $channel): array
    {
        $meta = $this->settings->get()->metadata ?? [];

        return is_array($meta['provider_verification'][$channel] ?? null) ? $meta['provider_verification'][$channel] : [];
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function storeVerification(string $channel, array $verification): void
    {
        $setting = $this->settings->get();
        $meta = is_array($setting->metadata) ? $setting->metadata : [];
        $meta['provider_verification'][$channel] = $verification;
        $setting->forceFill(['metadata' => $meta])->save();
    }

    /**
     * Hash of the active provider settings; secrets are hashed, never stored.
     */
    private function fingerprint(string $channel): string
    {
        $definition = $this->definition($channel);
        $values = [$definition['provider']];
        foreach ($definition['keys'] as $configKey) {
            $values[] = (string) config($configKey);
        }

        return hash('sha256', implode('|', $values));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function missing(array $definition): array
    {
        $missing = [];
        foreach ($definition['required'] as $env => $configKey) {
            if (is_array($configKey)) {
                $any = false;
                foreach ($configKey as $alt) {
                    $any = $any || filled(config($alt));
                }
                if (! $any) {
                    $missing[] = $env;
                }
            } elseif (! filled(config($configKey))) {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $channel): array
    {
        if ($channel === 'email') {
            $mailer = (string) config('mail.default');
            $required = $mailer === 'smtp' ? [
                'MAIL_HOST' => 'mail.mailers.smtp.host',
                'MAIL_PORT' => 'mail.mailers.smtp.port',
                'MAIL_FROM_ADDRESS' => 'mail.from.address',
            ] : ['MAIL_FROM_ADDRESS' => 'mail.from.address'];

            return [
                'provider' => $mailer,
                'required' => $required,
                'keys' => ['mail.mailers.smtp.host', 'mail.mailers.smtp.port', 'mail.mailers.smtp.username', 'mail.from.address'],
                'identifiers' => [
                    'mailer' => $mailer,
                    'host' => $mailer === 'smtp' ? (string) config('mail.mailers.smtp.host') : null,
                    'port' => $mailer === 'smtp' ? (int) config('mail.mailers.smtp.port') : null,
                    'username' => $this->mask((string) config('mail.mailers.smtp.username')),
                    'from_address' => (string) config('mail.from.address'),
                ],
                'webhooks' => [],
                'setup' => [
                    'Set MAIL_MAILER=smtp (or ses/postmark/resend) and the matching credentials in the server .env.',
                    'Set MAIL_FROM_ADDRESS to a domain with SPF, DKIM and DMARC records.',
                    'MAIL_MAILER=log writes messages to storage/logs and delivers nothing.',
                    'Send a test email below; the channel is marked verified only after the mail server accepts it.',
                    'Bounce/complaint webhooks are provider-specific and are not configured for generic SMTP; delivery is reported as "accepted" unless a provider callback confirms more.',
                ],
            ];
        }

        $provider = strtolower((string) config($channel === 'sms' ? 'communications.sms_provider' : 'communications.whatsapp_provider'));

        if ($channel === 'sms' && $provider === 'termii') {
            return [
                'provider' => 'termii',
                'required' => [
                    'TERMII_API_KEY' => 'communications.termii.api_key',
                    'TERMII_SENDER_ID' => 'communications.termii.sender_id',
                    'TERMII_SECRET_KEY (delivery report signing)' => 'communications.termii.secret_key',
                ],
                'keys' => ['communications.termii.api_key', 'communications.termii.sender_id', 'communications.termii.secret_key', 'communications.termii.base_url'],
                'identifiers' => [
                    'api_key' => $this->mask((string) config('communications.termii.api_key')),
                    'sender_id' => (string) config('communications.termii.sender_id'),
                    'base_url' => (string) config('communications.termii.base_url'),
                    'transactional_dnd_route' => (bool) config('communications.termii.transactional_dnd_route'),
                ],
                'webhooks' => ['delivery_reports' => CommunicationWebhookUrls::for('termii')],
                'setup' => [
                    'Create a Termii account and register a sender ID; Nigerian sender IDs require approval before use.',
                    'Set SMS_PROVIDER=termii, TERMII_API_KEY, TERMII_SENDER_ID and TERMII_SECRET_KEY in the server .env.',
                    'In the Termii dashboard set the webhook URL below for delivery reports (signed with your secret key).',
                    'Promotional messages use the generic route, which respects DND. The DND route is only used for transactional messages when TERMII_TRANSACTIONAL_DND_ROUTE=true.',
                    'Run "Check credentials" (reads balance, sends nothing), then send a test SMS to your own number.',
                ],
            ];
        }

        if ($provider === 'twilio') {
            $sender = $channel === 'sms'
                ? ['TWILIO_FROM or TWILIO_MESSAGING_SERVICE_SID' => ['communications.twilio.from', 'communications.twilio.messaging_service_sid']]
                : ['TWILIO_WHATSAPP_FROM' => 'communications.twilio.whatsapp_from'];

            return [
                'provider' => 'twilio',
                'required' => [
                    'TWILIO_ACCOUNT_SID' => 'communications.twilio.account_sid',
                    'TWILIO_AUTH_TOKEN' => 'communications.twilio.auth_token',
                    ...$sender,
                ],
                'keys' => ['communications.twilio.account_sid', 'communications.twilio.auth_token', 'communications.twilio.from', 'communications.twilio.messaging_service_sid', 'communications.twilio.whatsapp_from'],
                'identifiers' => [
                    'account_sid' => $this->mask((string) config('communications.twilio.account_sid')),
                    'sender' => $channel === 'sms'
                        ? ((string) config('communications.twilio.messaging_service_sid') !== '' ? $this->mask((string) config('communications.twilio.messaging_service_sid')) : $this->mask((string) config('communications.twilio.from')))
                        : $this->mask((string) config('communications.twilio.whatsapp_from')),
                ],
                'webhooks' => [
                    'status_callback' => CommunicationWebhookUrls::for('twilio'),
                    'inbound_messages' => CommunicationWebhookUrls::for('twilio'),
                ],
                'setup' => $channel === 'sms' ? [
                    'Set SMS_PROVIDER=twilio, TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM (or TWILIO_MESSAGING_SERVICE_SID).',
                    'APP_URL must be the public HTTPS API URL; status callbacks are requested per message and signed with your auth token.',
                    'Set the same webhook URL as the inbound messaging webhook so STOP replies are recorded as opt-outs.',
                    'Check credentials (reads the account, sends nothing), then send a test SMS.',
                ] : [
                    'Register a WhatsApp sender in Twilio and set WHATSAPP_PROVIDER=twilio and TWILIO_WHATSAPP_FROM.',
                    'Create and get approval for WhatsApp templates in Twilio Content Template Builder, then register each template below with its Content SID (HX...).',
                    'Business-initiated messages must use an approved template; opt-in is required for every recipient.',
                ],
                'extra' => $channel === 'whatsapp' ? ['approved_templates' => $this->approvedTemplateCount('twilio')] : [],
            ];
        }

        if ($channel === 'whatsapp' && in_array($provider, ['meta', 'cloud', 'whatsapp_cloud'], true)) {
            return [
                'provider' => 'meta',
                'required' => [
                    'WHATSAPP_ACCESS_TOKEN' => 'communications.meta_whatsapp.token',
                    'WHATSAPP_PHONE_NUMBER_ID' => 'communications.meta_whatsapp.phone_number_id',
                    'WHATSAPP_BUSINESS_ACCOUNT_ID' => 'communications.meta_whatsapp.business_account_id',
                    'WHATSAPP_APP_SECRET (webhook signature)' => 'communications.meta_whatsapp.app_secret',
                    'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => 'communications.meta_whatsapp.verify_token',
                ],
                'keys' => ['communications.meta_whatsapp.token', 'communications.meta_whatsapp.phone_number_id', 'communications.meta_whatsapp.business_account_id', 'communications.meta_whatsapp.app_secret', 'communications.meta_whatsapp.verify_token'],
                'identifiers' => [
                    'phone_number_id' => $this->mask((string) config('communications.meta_whatsapp.phone_number_id')),
                    'business_account_id' => $this->mask((string) config('communications.meta_whatsapp.business_account_id')),
                    'access_token' => $this->mask((string) config('communications.meta_whatsapp.token')),
                    'graph_version' => (string) config('communications.meta_whatsapp.graph_version'),
                ],
                'webhooks' => ['callback_url' => CommunicationWebhookUrls::for('meta')],
                'setup' => [
                    'In Meta Business Manager create a WhatsApp Business Account and phone number; create a System User with a permanent token (whatsapp_business_messaging, whatsapp_business_management).',
                    'Set WHATSAPP_PROVIDER=meta and the variables listed as missing in the server .env.',
                    'In the Meta app dashboard set the callback URL below and the verify token; subscribe to the "messages" field.',
                    'Use "Sync templates" to import approved templates. Campaigns can only use approved templates.',
                    'Every recipient needs a recorded WhatsApp opt-in before business-initiated messages.',
                ],
                'extra' => ['approved_templates' => $this->approvedTemplateCount('meta')],
            ];
        }

        $hint = $channel === 'sms' ? 'SMS_PROVIDER=termii or SMS_PROVIDER=twilio' : 'WHATSAPP_PROVIDER=meta or WHATSAPP_PROVIDER=twilio';

        return [
            'provider' => $provider === '' ? 'log' : $provider,
            'required' => [],
            'keys' => [],
            'identifiers' => [],
            'webhooks' => [],
            'setup' => [
                "No {$channel} provider is configured. Set {$hint} in the server .env with that provider's credentials.",
                $channel === 'whatsapp'
                    ? 'Only the official WhatsApp Business Platform (Meta Cloud API or Twilio) is supported. wa.me links are not bulk messaging.'
                    : 'Termii is recommended for Nigerian numbers (sender ID registration and DND handling); Twilio for international reach.',
            ],
        ];
    }

    private function approvedTemplateCount(string $provider): int
    {
        return CommunicationWhatsAppTemplate::query()->where('provider', $provider)->where('status', 'approved')->where('is_active', true)->count();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkMailer(): array
    {
        $probe = app(CommunicationMailHealthService::class)->snapshot()['provider'] ?? [];
        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            return ['ok' => false, 'message' => 'MAIL_MAILER is '.config('mail.default').'; no email is delivered.'];
        }

        return ($probe['reachable'] ?? false)
            ? ['ok' => true, 'message' => 'Mail transport reachable.']
            : ['ok' => false, 'message' => (string) ($probe['error'] ?? 'Mail transport unreachable.')];
    }

    private function mask(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $length = strlen($value);
        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', min(8, $length - 6)).substr($value, -4);
    }
}
