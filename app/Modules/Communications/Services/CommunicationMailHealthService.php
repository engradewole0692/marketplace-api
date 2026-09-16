<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Enums\EmailLogStatus;
use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Support\CommunicationEventKeys;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CommunicationMailHealthService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationSettingsService $settings,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function snapshot(): array
  {
    $setting = $this->settings->get();
    $since = now()->subDay();
    $sent = CommunicationEmailLog::query()->where('status', EmailLogStatus::Sent)->where('created_at', '>=', $since)->count();
    $failed = CommunicationEmailLog::query()->where('status', EmailLogStatus::Failed)->where('created_at', '>=', $since)->count();
    $queued = CommunicationEmailLog::query()->where('status', EmailLogStatus::Queued)->where('created_at', '>=', $since)->count();

    $catalogKeys = CommunicationEventKeys::all();
    $dbKeys = CommunicationTemplate::query()->pluck('event_key')->all();
    $activeKeys = CommunicationTemplate::query()->where('is_active', true)->pluck('event_key')->all();
    $missingTemplates = array_values(array_diff($catalogKeys, $dbKeys));
    $inactiveRequired = array_values(array_intersect($catalogKeys, array_diff($dbKeys, $activeKeys)));

    return [
      'mailer' => (string) config('mail.default'),
      'from_address' => (string) config('mail.from.address'),
      'from_name' => (string) config('mail.from.name'),
      'smtp_host' => (string) config('mail.mailers.smtp.host'),
      'smtp_port' => (int) config('mail.mailers.smtp.port'),
      'smtp_encryption' => config('mail.mailers.smtp.encryption') ?: config('mail.mailers.smtp.scheme'),
      'smtp_username_configured' => filled(config('mail.mailers.smtp.username')),
      'queue_connection' => (string) config('queue.default'),
      'communications_dispatch' => 'synchronous',
      'bulk_email_dispatch' => 'queued',
      'delayed_member_notifications' => 'queued',
      'ministry_email_configured' => filled($setting->ministry_email) || filled($this->settings->ministryEmail()),
      'reply_to_configured' => filled($setting->reply_to_email),
      'last_24h' => [
        'sent' => $sent,
        'failed' => $failed,
        'queued' => $queued,
      ],
      'catalog_event_keys' => count($catalogKeys),
      'stored_templates' => count($dbKeys),
      'missing_templates' => $missingTemplates,
      'inactive_required_templates' => $inactiveRequired,
      'provider' => $this->probeMailer(),
      'delivery_capable' => ! in_array((string) config('mail.default'), ['log', 'array'], true),
      'channels' => app(\App\Modules\Communications\Services\OutboundMessageService::class)->status(),
      'settings' => [
        'ministry_email' => $setting->ministry_email ?: $this->settings->ministryEmail(),
        'reply_to_email' => $setting->reply_to_email,
        'from_name' => $setting->from_name,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function probeMailer(): array
  {
    $mailer = (string) config('mail.default');
    if (in_array($mailer, ['log', 'array'], true)) {
      return [
        'reachable' => true,
        'mode' => $mailer,
        'note' => 'Messages are written locally and are not delivered to real inboxes.',
      ];
    }

    try {
      $transport = Mail::mailer()->getSymfonyTransport();
      if (method_exists($transport, 'start') && ! in_array($mailer, ['log', 'array'], true)) {
        $transport->start();
      }

      return [
        'reachable' => true,
        'mode' => $mailer,
      ];
    } catch (\Throwable $exception) {
      return [
        'reachable' => false,
        'mode' => $mailer,
        'error' => Str::limit(
          preg_replace('/(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $exception->getMessage()) ?? $exception->getMessage(),
          300,
        ),
      ];
    }
  }
}
