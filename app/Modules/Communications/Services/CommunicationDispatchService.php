<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Mail\MemberNotificationMail;
use App\Models\User;
use App\Modules\Communications\Enums\EmailLogStatus;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Support\CommunicationEventKeys;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Central email dispatch — templates, routing, logging, safe failure handling.
 */
final class CommunicationDispatchService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationSettingsService $settings,
    private readonly CommunicationRoutingService $routing,
    private readonly CommunicationTemplateRenderer $renderer,
    private readonly MemberNotificationQueueService $memberQueue,
    private readonly CommunicationIdempotencyService $idempotency,
  ) {}

  /**
   * @param  array<string, mixed>  $variables
   * @param  array<string, mixed>  $context
   */
  public function dispatchEvent(
    string $eventKey,
    string $section,
    array $variables = [],
    array $context = [],
    ?User $recipientUser = null,
    ?string $recipientEmail = null,
    ?string $recipientName = null,
    ?Model $related = null,
    bool $isTest = false,
    bool $includeRouting = true,
    ?string $idempotencyKey = null,
  ): void {
    if ($idempotencyKey !== null && $this->idempotency->alreadyDispatched($idempotencyKey)) {
      return;
    }

    $variables = $this->flattenVariables($variables);

    $template = CommunicationTemplate::query()
      ->where('event_key', $eventKey)
      ->where('is_active', true)
      ->first();

    if ($recipientEmail) {
      $this->sendToAddress(
        $eventKey,
        $section,
        $recipientEmail,
        $recipientName ?? (string) ($variables['applicant_name'] ?? $variables['member_name'] ?? 'Friend'),
        $template,
        $variables,
        $context,
        $recipientUser,
        $related,
        $isTest,
        'to',
        $idempotencyKey,
      );
    }

    if ($recipientUser?->email && $recipientUser->email !== $recipientEmail) {
      $this->sendToAddress(
        $eventKey,
        $section,
        $recipientUser->email,
        $recipientUser->display_name ?: $recipientUser->name ?: 'Friend',
        $template,
        $variables,
        $context,
        $recipientUser,
        $related,
        $isTest,
        'to',
        $idempotencyKey ? $this->idempotency->compose($idempotencyKey, $recipientUser->email) : null,
      );
    }

    if (! $isTest && $includeRouting) {
      $this->dispatchAdminRecipients($eventKey, $section, $template, $variables, $context, $related, $idempotencyKey);
    }

    if ($recipientUser?->member && ! $isTest) {
      $this->queueInApp($recipientUser, $eventKey, $variables, (string) ($variables['in_app_title'] ?? $variables['subject'] ?? 'Notification'));
    }
  }

  /**
   * @param  array<string, mixed>  $variables
   * @param  array<string, mixed>  $context
   */
  public function sendTestEmail(
    CommunicationTemplate $template,
    string $recipientEmail,
    array $variables = [],
  ): CommunicationEmailLog {
    $sample = $template->sample_variables ?? [];
    $merged = $this->flattenVariables(array_merge($sample, $variables));

    return $this->sendToAddress(
      $template->event_key,
      $template->section,
      $recipientEmail,
      (string) ($merged['applicant_name'] ?? 'Test Recipient'),
      $template,
      $merged,
      [],
      null,
      null,
      true,
      'to',
    );
  }

  /**
   * Send one transactional email through the same pipeline used by application events.
   *
   * @param  array<string, mixed>  $variables
   */
  public function sendDirect(
    string $eventKey,
    string $section,
    string $recipientEmail,
    string $recipientName,
    array $variables = [],
    ?Model $related = null,
    ?string $idempotencyKey = null,
    bool $includeRouting = false,
  ): CommunicationEmailLog {
    $variables = $this->flattenVariables($variables);
    $template = CommunicationTemplate::query()
      ->where('event_key', $eventKey)
      ->where('is_active', true)
      ->first();

    $log = $this->sendToAddress(
      $eventKey,
      $section,
      $recipientEmail,
      $recipientName,
      $template,
      $variables,
      [],
      null,
      $related,
      false,
      'to',
      $idempotencyKey,
    );

    if ($includeRouting) {
      $this->dispatchAdminRecipients($eventKey, $section, $template, $variables, [], $related, $idempotencyKey);
    }

    return $log;
  }

  /**
   * @param  array<string, mixed>  $variables
   * @param  array<string, mixed>  $context
   */
  private function dispatchAdminRecipients(
    string $eventKey,
    string $section,
    ?CommunicationTemplate $template,
    array $variables,
    array $context,
    ?Model $related,
    ?string $idempotencyKey = null,
  ): void {
    $resolved = $this->routing->resolve($section, $eventKey, $context);
    foreach (['to', 'cc', 'bcc'] as $role) {
      foreach ($resolved[$role] as $email) {
        if ($email === ($variables['applicant_email'] ?? null) || $email === ($variables['email'] ?? null)) {
          continue;
        }
        $key = $idempotencyKey ? $this->idempotency->compose($idempotencyKey, "{$role}:{$email}") : null;
        if ($key && $this->idempotency->alreadyDispatched($key)) {
          continue;
        }
        $this->sendToAddress(
          $eventKey,
          $section,
          $email,
          'Administrator',
          $template,
          $variables,
          $context,
          null,
          $related,
          false,
          $role,
          $key,
        );
        if ($key) {
          $this->idempotency->record($key, $eventKey);
        }
      }
    }
  }

  /**
   * @param  array<string, mixed>  $variables
   * @param  array<string, mixed>  $context
   */
  private function sendToAddress(
    string $eventKey,
    string $section,
    string $email,
    string $recipientName,
    ?CommunicationTemplate $template,
    array $variables,
    array $context,
    ?User $user,
    ?Model $related,
    bool $isTest,
    string $role,
    ?string $idempotencyKey = null,
  ): CommunicationEmailLog {
    if ($idempotencyKey && $this->idempotency->alreadyDispatched($idempotencyKey)) {
      return CommunicationEmailLog::query()->where('recipient_email', $email)
        ->where('event_key', $eventKey)
        ->latest()
        ->first() ?? new CommunicationEmailLog([
          'event_key' => $eventKey,
          'recipient_email' => $email,
          'subject' => 'Skipped duplicate',
          'status' => EmailLogStatus::Sent,
        ]);
    }

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return CommunicationEmailLog::query()->create([
        'event_key' => $eventKey,
        'section' => $section,
        'recipient_email' => $email ?: 'invalid',
        'subject' => 'Invalid recipient',
        'status' => EmailLogStatus::Failed,
        'error_message' => 'Invalid email address',
        'is_test' => $isTest,
      ]);
    }
    $settings = $this->settings->get();
    $branding = $settings->branding ?? [];
    $vars = $this->flattenVariables(array_merge($variables, [
      'admin_name' => $recipientName,
      'site_name' => $branding['site_name'] ?? 'Marketplace Ministers',
      'applicant_email' => (string) ($variables['applicant_email'] ?? $variables['email'] ?? ''),
    ]));

    [$replyToEmail, $replyToName] = $this->resolveReplyTo($eventKey, $role, $settings, $vars);

    $log = CommunicationEmailLog::query()->create([
      'template_id' => $template?->id,
      'event_key' => $eventKey,
      'section' => $section,
      'recipient_email' => $email,
      'sender_email' => config('mail.from.address'),
      'subject' => $template
        ? $this->renderer->render($template->subject, $vars)
        : 'Marketplace Ministers notification',
      'status' => EmailLogStatus::Queued,
      'is_test' => $isTest,
      'related_type' => $related ? $related->getMorphClass() : null,
      'related_id' => $related ? (string) $related->getKey() : null,
      'user_id' => $user?->id,
      'metadata' => [
        'role' => $role,
        'context' => array_keys($context),
        'template_missing' => $template === null,
        'mailer' => (string) config('mail.default'),
      ],
    ]);

    try {
      $sentMessage = null;
      if ($template instanceof CommunicationTemplate) {
        $body = $this->renderer->render($template->html_body, $vars);
        $html = $this->renderer->wrapWithBranding($body, $vars, $branding);
        $sentMessage = Mail::to($email)->send(new CommunicationMailable(
          mailSubject: $log->subject,
          htmlBody: $html,
          textBody: $template->text_body ? $this->renderer->render($template->text_body, $vars) : null,
          replyToEmail: $replyToEmail,
          replyToName: $replyToName,
          fromName: $settings->from_name,
        ));
      } else {
        $sentMessage = Mail::to($email)->send(new MemberNotificationMail(
          $this->legacyTemplateKey($eventKey),
          $vars,
          $recipientName,
        ));
        $log->subject = (new MemberNotificationMail($this->legacyTemplateKey($eventKey), $vars, $recipientName))->envelope()->subject;
        $log->save();
      }

      $messageId = $this->providerMessageId($sentMessage);
      $metadata = is_array($log->metadata) ? $log->metadata : [];
      if ($messageId) {
        $metadata['provider_message_id'] = $messageId;
      }
      $mailer = (string) config('mail.default');
      $metadata['delivery_mode'] = $mailer;
      $delivered = ! in_array($mailer, ['log'], true);

      $log->fill([
        'status' => $delivered ? EmailLogStatus::Sent : EmailLogStatus::Skipped,
        'sent_at' => $delivered ? now() : null,
        'metadata' => $metadata,
        'error_message' => $delivered ? null : 'Mailer is log; message was not delivered to an inbox.',
      ])->save();

      if ($idempotencyKey) {
        $this->idempotency->record($idempotencyKey, $eventKey);
      }
    } catch (\Throwable $exception) {
      report($exception);
      $log->fill([
        'status' => EmailLogStatus::Failed,
        'failed_at' => now(),
        'error_message' => $this->safeErrorMessage($exception->getMessage()),
      ])->save();
    }

    return $log->fresh() ?? $log;
  }

  /**
   * @param  array<string, mixed>  $variables
   * @return array<string, mixed>
   */
  private function flattenVariables(array $variables): array
  {
    $flat = [];
    foreach ($variables as $key => $value) {
      if (! is_string($key) || $key === '') {
        continue;
      }
      $flat[$key] = $this->rendererStringify($value);
    }

    return $flat;
  }

  private function rendererStringify(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? 'Yes' : 'No';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        if (is_scalar($item) || $item === null) {
          $parts[] = $item === null ? '' : (string) $item;
        }
      }

      return implode(', ', array_filter($parts, fn ($part) => $part !== ''));
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }

    return '';
  }

  /**
   * @param  array<string, mixed>  $vars
   * @return array{0: ?string, 1: ?string}
   */
  private function resolveReplyTo(string $eventKey, string $role, \App\Modules\Communications\Models\CommunicationSetting $settings, array $vars): array
  {
    $configured = is_string($settings->reply_to_email) && $settings->reply_to_email !== ''
      ? $settings->reply_to_email
      : (string) (config('cms.notifications.reply_to_email') ?: config('mail.from.address'));
    $configuredName = $settings->reply_to_name ?: $settings->from_name;

    $applicant = (string) ($vars['email'] ?? $vars['applicant_email'] ?? '');
    if (
      (CommunicationEventKeys::isAdminAlert($eventKey) || in_array($role, ['cc', 'bcc'], true))
      && filter_var($applicant, FILTER_VALIDATE_EMAIL)
    ) {
      return [$applicant, (string) ($vars['applicant_name'] ?? $vars['member_name'] ?? 'Applicant')];
    }

    return [
      filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : null,
      $configuredName,
    ];
  }

  private function providerMessageId(mixed $sentMessage): ?string
  {
    if (! is_object($sentMessage)) {
      return null;
    }
    if (method_exists($sentMessage, 'getMessageId')) {
      $id = $sentMessage->getMessageId();

      return is_string($id) && $id !== '' ? $id : null;
    }
    if (method_exists($sentMessage, 'getSymfonySentMessage')) {
      $symfony = $sentMessage->getSymfonySentMessage();
      $id = is_object($symfony) && method_exists($symfony, 'getMessageId') ? $symfony->getMessageId() : null;

      return is_string($id) && $id !== '' ? $id : null;
    }

    return null;
  }

  private function safeErrorMessage(string $message): string
  {
    $redacted = preg_replace('/(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $message) ?? $message;

    return Str::limit($redacted, 1000);
  }

  /**
   * @param  array<string, mixed>  $variables
   */
  private function queueInApp(User $user, string $eventKey, array $variables, string $title): void
  {
    $member = $user->member;
    if ($member === null) {
      return;
    }

    try {
      $this->memberQueue->queue($member, 'in_app', $this->legacyTemplateKey($eventKey), array_merge($variables, [
        'title' => $title,
        'body' => (string) ($variables['in_app_body'] ?? $variables['course_title'] ?? $title),
      ]));
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  private function legacyTemplateKey(string $eventKey): string
  {
    return match ($eventKey) {
      CommunicationEventKeys::FORM_MEMBERSHIP_SUBMITTED => 'application_submitted',
      CommunicationEventKeys::FORM_MEMBERSHIP_SUBMITTED_ADMIN => 'application_submitted_admin',
      CommunicationEventKeys::FORM_COUNSELING_SUBMITTED,
      CommunicationEventKeys::COUNSELING_REQUEST_SUBMITTED => 'counselling.request_submitted',
      CommunicationEventKeys::FORM_COUNSELING_SUBMITTED_ADMIN => 'counselling.request_submitted_admin',
      CommunicationEventKeys::MEMBERSHIP_APPLICATION_APPROVED => 'application_approved',
      CommunicationEventKeys::MEMBERSHIP_REQUEST_MORE_INFORMATION => 'request_more_information',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_INVITATION => 'interview_invitation',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_RESCHEDULED => 'interview_rescheduled',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_CONFIRMED => 'interview_confirmed',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_REMINDER => 'interview_reminder',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_PASSED => 'interview_passed',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_FAILED => 'interview_failed',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_AWAITING_REVIEW => 'interview_awaiting_review',
      CommunicationEventKeys::MEMBERSHIP_INTERVIEW_CANCELLED => 'interview_cancelled',
      CommunicationEventKeys::MEMBERSHIP_ACCOUNT_CREATED => 'member_account_created',
      CommunicationEventKeys::MEMBERSHIP_ACCOUNT_UPGRADED => 'member_account_upgraded',
      CommunicationEventKeys::MEMBERSHIP_WELCOME => 'member_welcome',
      CommunicationEventKeys::MEMBERSHIP_MINISTRY_ONBOARDING => 'ministry_country_onboarding',
      CommunicationEventKeys::COUNSELING_PAYMENT_REQUIRED => 'counselling.payment_required',
      CommunicationEventKeys::COUNSELING_PAYMENT_RECEIVED => 'counselling.payment_received',
      default => $eventKey,
    };
  }
}
