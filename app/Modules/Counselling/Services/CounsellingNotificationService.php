<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Mail\MemberNotificationMail;
use App\Models\User;
use App\Modules\Counselling\Models\CounsellingAppointment;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingPayment;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Support\Facades\Mail;

final class CounsellingNotificationService implements ServiceContract
{
  public function __construct(
    private readonly MemberNotificationQueueService $memberQueue,
  ) {}

  public function notifyRequestSubmitted(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.request_submitted', [
      'case_number' => $case->case_number,
      'service_title' => $case->service?->title,
      'client_name' => $case->client_name,
    ], 'Counselling request submitted');
  }

  public function notifyPaymentRequired(CounsellingCase $case, CounsellingPayment $payment): void
  {
    $this->notifyCaseClient($case, 'counselling.payment_required', [
      'case_number' => $case->case_number,
      'amount' => (float) $payment->amount,
      'currency' => $payment->currency,
      'payment_id' => $payment->uuid,
    ], 'Payment required for counselling');
  }

  public function notifyPaymentReceived(CounsellingCase $case, CounsellingPayment $payment): void
  {
    $this->notifyCaseClient($case, 'counselling.payment_received', [
      'case_number' => $case->case_number,
      'amount' => (float) $payment->amount,
      'currency' => $payment->currency,
      'payment_id' => $payment->uuid,
    ], 'Payment received');
  }

  public function notifyAppointmentScheduled(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyCaseClient($case, 'counselling.appointment_scheduled', [
      'case_number' => $case->case_number,
      'appointment_id' => $appointment->uuid,
      'starts_at' => $appointment->starts_at?->toIso8601String(),
      'format' => $appointment->format instanceof \BackedEnum ? $appointment->format->value : $appointment->format,
    ], 'Counselling appointment scheduled');
  }

  public function notifyReminder(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyCaseClient($case, 'counselling.reminder', [
      'case_number' => $case->case_number,
      'appointment_id' => $appointment->uuid,
      'starts_at' => $appointment->starts_at?->toIso8601String(),
    ], 'Upcoming counselling appointment');
  }

  public function notifyRescheduled(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyCaseClient($case, 'counselling.rescheduled', [
      'case_number' => $case->case_number,
      'appointment_id' => $appointment->uuid,
      'starts_at' => $appointment->starts_at?->toIso8601String(),
    ], 'Counselling appointment rescheduled');
  }

  public function notifyCancelled(CounsellingCase $case, ?string $reason = null): void
  {
    $this->notifyCaseClient($case, 'counselling.cancelled', [
      'case_number' => $case->case_number,
      'reason' => $reason ?? $case->cancellation_reason,
    ], 'Counselling case cancelled');
  }

  public function notifyCompleted(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.completed', [
      'case_number' => $case->case_number,
      'service_title' => $case->service?->title,
    ], 'Counselling completed');
  }

  public function notifyFeedbackRequest(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.feedback_request', [
      'case_number' => $case->case_number,
      'service_title' => $case->service?->title,
    ], 'Share your counselling feedback');
  }

  public function notifyAdminsNewRequest(CounsellingCase $case): void
  {
    $this->notifyPermissionHolders('counselling.manage', 'counselling.request_submitted_admin', [
      'case_number' => $case->case_number,
      'client_name' => $case->client_name,
      'client_email' => $case->client_email,
      'service_title' => $case->service?->title,
    ], 'New counselling request');
  }

  public function notifyCaseAccepted(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.case_accepted', [
      'case_number' => $case->case_number,
    ], 'Counselling case under review');
  }

  public function notifyCaseRejected(CounsellingCase $case, ?string $reason = null): void
  {
    $this->notifyCaseClient($case, 'counselling.case_rejected', [
      'case_number' => $case->case_number,
      'reason' => $reason,
    ], 'Counselling case rejected');
  }

  public function notifyMoreInfoRequested(CounsellingCase $case, ?string $note = null): void
  {
    $this->notifyCaseClient($case, 'counselling.more_info_requested', [
      'case_number' => $case->case_number,
      'reason' => $note,
    ], 'More information requested');
  }

  public function notifyCaseClosed(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.case_closed', [
      'case_number' => $case->case_number,
    ], 'Counselling case closed');
  }

  public function notifyCounsellorAssigned(CounsellingCase $case, \App\Modules\Counselling\Models\Counsellor $counsellor): void
  {
    $counsellor->loadMissing('user');
    $user = $counsellor->user;
    if ($user === null || $user->email === null || $user->email === '') {
      return;
    }

    $payload = [
      'case_number' => $case->case_number,
      'client_name' => $case->client_name,
      'service_title' => $case->service?->title,
    ];

    try {
      Mail::to($user->email)->queue(new MemberNotificationMail(
        'counselling.counsellor_assigned',
        $payload,
        $counsellor->display_name ?: $user->name ?: 'Counsellor',
      ));
    } catch (\Throwable $exception) {
      report($exception);
    }

    if ($user->member !== null) {
      $this->memberQueue->queue($user->member, 'in_app', 'counselling.counsellor_assigned', array_merge($payload, [
        'title' => 'New counselling assignment',
        'body' => 'Case '.$case->case_number.' was assigned to you.',
      ]));
    }
  }

  public function notifyClientCounsellorAssigned(CounsellingCase $case): void
  {
    $this->notifyCaseClient($case, 'counselling.counsellor_assigned_client', [
      'case_number' => $case->case_number,
      'counsellor_name' => $case->counsellor?->display_name,
    ], 'Counsellor assigned to your case');
  }

  public function notifyMessageReceived(CounsellingCase $case, string $recipientRole, string $preview): void
  {
    if ($recipientRole === 'client') {
      $this->notifyCaseClient($case, 'counselling.message_received', [
        'case_number' => $case->case_number,
        'reason' => $preview,
      ], 'New counselling message');

      return;
    }

    $case->loadMissing('counsellor.user.member');
    $user = $case->counsellor?->user;
    if ($user === null || empty($user->email)) {
      return;
    }

    try {
      Mail::to($user->email)->queue(new MemberNotificationMail(
        'counselling.message_received',
        ['case_number' => $case->case_number, 'reason' => $preview],
        $case->counsellor?->display_name ?: $user->name ?: 'Counsellor',
      ));
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function notifyPermissionHolders(
    string $permissionSlug,
    string $template,
    array $payload,
    string $inAppTitle,
  ): void {
    $users = User::query()
      ->whereHas('permissions', fn ($q) => $q->where('slug', $permissionSlug))
      ->orWhereHas('roles.permissions', fn ($q) => $q->where('slug', $permissionSlug))
      ->get();

    foreach ($users as $user) {
      if (empty($user->email)) {
        continue;
      }

      try {
        Mail::to($user->email)->queue(new MemberNotificationMail(
          $template,
          $payload,
          $user->display_name ?: $user->name ?: 'Admin',
        ));
      } catch (\Throwable $exception) {
        report($exception);
      }
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function notifyCaseClient(
    CounsellingCase $case,
    string $template,
    array $payload,
    string $inAppTitle,
  ): void {
    $case->loadMissing(['user.member', 'member']);

    $user = $case->user;
    $member = $case->member ?? $user?->member;

    if ($member !== null) {
      $this->memberQueue->queue($member, 'email', $template, array_merge($payload, [
        'email' => $case->client_email,
      ]));
      $this->memberQueue->queue($member, 'in_app', $template, array_merge($payload, [
        'title' => $inAppTitle,
        'body' => (string) ($payload['service_title'] ?? $payload['case_number'] ?? $inAppTitle),
      ]));

      return;
    }

    $recipientName = $case->client_name ?: 'Client';
    $email = $case->client_email;

    if ($user !== null) {
      $email = $user->email;
      $recipientName = $user->display_name ?: $user->name ?: $recipientName;
    }

    if ($email === '') {
      return;
    }

    try {
      Mail::to($email)->queue(new MemberNotificationMail(
        $template,
        array_merge($payload, ['email' => $email]),
        $recipientName,
      ));
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
