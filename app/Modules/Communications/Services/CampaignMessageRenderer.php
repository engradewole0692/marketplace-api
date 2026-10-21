<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Models\BulkEmailJob;
use Illuminate\Support\Facades\URL;

/**
 * Per-recipient personalization for campaigns. Variables are HTML-escaped in email bodies and
 * decoded back to plain text for SMS/WhatsApp.
 */
final class CampaignMessageRenderer implements ServiceContract
{
    public const VARIABLES = ['first_name', 'name', 'email', 'site_name', 'unsubscribe_url'];

    public function __construct(
        private readonly CommunicationTemplateRenderer $renderer,
        private readonly CommunicationSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $recipient  name, email, first_name
     * @return array<string, string>
     */
    public function variables(array $recipient, BulkEmailJob $job, string $channel): array
    {
        $name = trim((string) ($recipient['name'] ?? ''));
        $first = trim((string) ($recipient['first_name'] ?? ''));
        if ($first === '' && $name !== '') {
            $first = explode(' ', $name)[0];
        }
        $branding = $this->settings->get()->branding ?? [];

        $vars = [
            'first_name' => $first !== '' ? $first : 'Friend',
            'name' => $name !== '' ? $name : 'Friend',
            'email' => (string) ($recipient['email'] ?? ''),
            'site_name' => (string) ($branding['site_name'] ?? 'Marketplace Ministers'),
            'unsubscribe_url' => '',
        ];
        if ($channel === 'email' && $job->message_type !== 'transactional' && filled($recipient['destination'] ?? $recipient['email'] ?? null)) {
            $vars['unsubscribe_url'] = $this->unsubscribeUrl('email', (string) ($recipient['destination'] ?? $recipient['email']));
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, html: string, text: string}
     */
    public function email(BulkEmailJob $job, array $vars): array
    {
        $branding = $this->settings->get()->branding ?? [];
        $body = $this->renderer->render($this->sanitizeHtml((string) $job->html_body), $vars);
        if ($vars['unsubscribe_url'] !== '' && ! str_contains((string) $job->html_body, '{{unsubscribe_url}}')) {
            $body .= '<p style="margin-top:24px;font-size:12px;color:#78716c">You are receiving this because you are connected with '
                .e($vars['site_name']).'. <a href="'.e($vars['unsubscribe_url']).'">Unsubscribe</a></p>';
        }
        $textSource = filled($job->text_body) ? (string) $job->text_body : $this->htmlToText((string) $job->html_body);
        $text = $this->plain($this->renderer->render($textSource, $vars));
        if ($vars['unsubscribe_url'] !== '') {
            $text .= "\n\nUnsubscribe: ".$vars['unsubscribe_url'];
        }

        return [
            'subject' => $this->plain($this->renderer->render((string) $job->subject, $vars)),
            'html' => $this->renderer->wrapWithBranding($body, $vars, $branding),
            'text' => $text,
        ];
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function text(BulkEmailJob $job, array $vars): string
    {
        $source = filled($job->text_body) ? (string) $job->text_body : $this->htmlToText((string) $job->html_body);

        return $this->plain($this->renderer->render($source, $vars));
    }

    /**
     * Resolve approved-template parameters for one recipient.
     *
     * @param  array<string, string>  $vars
     * @return array{name: string, language: string, provider_template_id: ?string, parameters: list<string>}|null
     */
    public function whatsappTemplate(BulkEmailJob $job, array $vars): ?array
    {
        $template = $job->whatsapp_template;
        if (! is_array($template) || empty($template['name'])) {
            return null;
        }
        $parameters = [];
        foreach ((array) ($template['parameters'] ?? []) as $param) {
            $source = (string) ($param['source'] ?? 'fixed');
            $parameters[] = str_starts_with($source, 'variable:')
                ? (string) ($vars[substr($source, 9)] ?? '')
                : (string) ($param['value'] ?? '');
        }

        return [
            'name' => (string) $template['name'],
            'language' => (string) ($template['language'] ?? 'en'),
            'provider_template_id' => $template['provider_template_id'] ?? null,
            'parameters' => $parameters,
        ];
    }

    public function unsubscribeUrl(string $channel, string $destination): string
    {
        return URL::signedRoute('api.v1.communications.public.unsubscribe', [
            'channel' => $channel,
            'address' => rtrim(strtr(base64_encode(strtolower($destination)), '+/', '-_'), '='),
        ]);
    }

    /**
     * Strip active content from admin-authored HTML: scripts, embeds, event handlers and javascript: URLs.
     */
    public function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'\s>]*\2#i', '$1="#"', $html) ?? '';

        return $html;
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function plain(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
