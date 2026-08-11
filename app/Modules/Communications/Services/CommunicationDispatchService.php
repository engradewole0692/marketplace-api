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
    $merged = array_merge($sample, $variables);

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
    $vars = array_merge($variables, [
      'admin_name' => $recipientName,
      'site_name' => $branding['site_name'] ?? 'Marketplace Ministers',
    ]);

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
      'metadata' => ['role' => $role, 'context' => array_keys($context)],
    ]);

    try {
      if ($template instanceof CommunicationTemplate) {
        $body = $this->renderer->render($template->html_body, $vars);
        $html = $this->renderer->wrapWithBranding($body, $vars, $branding);
        Mail::to($email)->send(new CommunicationMailable(
          mailSubject: $log->subject,
          htmlBody: $html,
          textBody: $template->text_body ? $this->renderer->render($template->text_body, $vars) : null,
          replyToEmail: $settings->reply_to_email,
          replyToName: $settings->reply_to_name,
          fromName: $settings->from_name,
        ));
      } else {
        Mail::to($email)->send(new MemberNotificationMail(
          $this->legacyTemplateKey($eventKey),
          $vars,
          $recipientName,
        ));
        $log->subject = (new MemberNotificationMail($this->legacyTemplateKey($eventKey), $vars, $recipientName))->envelope()->subject;
        $log->save();
      }

      $log->fill([
        'status' => EmailLogStatus::Sent,
        'sent_at' => now(),
      ])->save();

      if ($idempotencyKey) {
        $this->idempotency->record($idempotencyKey, $eventKey);
      }
    } catch (\Throwable $exception) {
      report($exception);
      $log->fill([
        'status' => EmailLogStatus::Failed,
        'failed_at' => now(),
        'error_message' => Str::limit($exception->getMessage(), 1000),
      ])->save();
    }

    return $log->fresh();
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
      'form.membership.submitted' => 'application_submitted',
      'form.membership.submitted.admin' => 'application_submitted_admin',
      'form.counseling.submitted' => 'counselling.request_submitted',
      'form.counseling.submitted.admin' => 'counselling.request_submitted_admin',
      'lms.payment.confirmed' => 'lms.payment.confirmed',
      'lms.school.payment.confirmed' => 'lms.school.payment.confirmed',
      default => $eventKey,
    };
  }
}
