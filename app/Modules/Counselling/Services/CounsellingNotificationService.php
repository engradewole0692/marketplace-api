<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Counselling\Models\CounsellingAppointment;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingPayment;
use App\Modules\Counselling\Models\Counsellor;

final class CounsellingNotificationService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationDispatchService $communicationDispatch,
  ) {}

  public function notifyRequestSubmitted(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.request.submitted', [
      'case_number' => $case->case_number,
      'service_title' => $case->service?->title,
    ], 'Counselling request submitted', "counseling.request.submitted:{$case->uuid}");
    $this->notifyAdminsNewRequest($case);
  }

  public function notifyPaymentRequired(CounsellingCase $case, CounsellingPayment $payment): void
  {
    $this->notifyClient($case, 'counseling.payment.required', [
      'case_number' => $case->case_number,
      'amount' => number_format((float) $payment->amount, 2),
      'currency' => $payment->currency,
      'payment_reference' => $payment->uuid,
    ], 'Payment required for counselling', "counseling.payment.required:{$case->uuid}:{$payment->uuid}");
  }

  public function notifyPaymentReceived(CounsellingCase $case, CounsellingPayment $payment): void
  {
    $this->notifyClient($case, 'counseling.payment.received', [
      'case_number' => $case->case_number,
      'amount' => number_format((float) $payment->amount, 2),
      'currency' => $payment->currency,
      'payment_reference' => $payment->uuid,
    ], 'Payment received', "counseling.payment.received:{$case->uuid}:{$payment->uuid}");
  }

  public function notifyAppointmentScheduled(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyClient($case, 'counseling.appointment.scheduled', [
      'case_number' => $case->case_number,
      'event_date' => $appointment->starts_at?->format('M j, Y') ?? '',
      'event_time' => $appointment->starts_at?->format('g:i A') ?? '',
    ], 'Counselling appointment scheduled', "counseling.appointment.scheduled:{$appointment->uuid}");
  }

  public function notifyReminder(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyClient($case, 'counseling.appointment.scheduled', [
      'case_number' => $case->case_number,
      'event_date' => $appointment->starts_at?->format('M j, Y') ?? '',
      'event_time' => $appointment->starts_at?->format('g:i A') ?? '',
    ], 'Upcoming counselling appointment', "counseling.reminder:{$appointment->uuid}");
  }

  public function notifyRescheduled(CounsellingCase $case, CounsellingAppointment $appointment): void
  {
    $this->notifyClient($case, 'counseling.appointment.scheduled', [
      'case_number' => $case->case_number,
      'event_date' => $appointment->starts_at?->format('M j, Y') ?? '',
      'event_time' => $appointment->starts_at?->format('g:i A') ?? '',
      'reason' => 'Appointment rescheduled',
    ], 'Counselling appointment rescheduled', "counseling.rescheduled:{$appointment->uuid}");
  }

  public function notifyCancelled(CounsellingCase $case, ?string $reason = null): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'Cancelled',
      'reason' => $reason ?? $case->cancellation_reason,
    ], 'Counselling case cancelled', "counseling.cancelled:{$case->uuid}");
  }

  public function notifyCompleted(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'Completed',
      'service_title' => $case->service?->title,
    ], 'Counselling completed', "counseling.completed:{$case->uuid}");
  }

  public function notifyFeedbackRequest(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'Feedback requested',
      'service_title' => $case->service?->title,
    ], 'Share your counselling feedback', "counseling.feedback:{$case->uuid}");
  }

  public function notifyAdminsNewRequest(CounsellingCase $case): void
  {
    $case->loadMissing(['service', 'counsellor.user']);
    $this->dispatchAdmin($case, 'form.counseling.submitted.admin', [
      'case_number' => $case->case_number,
      'client_name' => $case->client_name,
      'applicant_name' => $case->client_name,
      'email' => $case->client_email,
      'service_title' => $case->service?->title,
    ], "counseling.admin.new:{$case->uuid}");
  }

  public function notifyCaseAccepted(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'Under review',
    ], 'Counselling case under review', "counseling.accepted:{$case->uuid}");
  }

  public function notifyCaseRejected(CounsellingCase $case, ?string $reason = null): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'Rejected',
      'reason' => $reason,
    ], 'Counselling case update', "counseling.rejected:{$case->uuid}");
  }

  public function notifyMoreInfoRequested(CounsellingCase $case, ?string $note = null): void
  {
    $this->notifyClient($case, 'counseling.status.updated', [
      'case_number' => $case->case_number,
      'application_status' => 'More information requested',
      'reason' => $note,
    ], 'More information requested', "counseling.more_info:{$case->uuid}");
  }

  public function notifyCaseClosed(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.case.closed', [
      'case_number' => $case->case_number,
    ], 'Counselling case closed', "counseling.closed:{$case->uuid}");
  }

  public function notifyCounsellorAssigned(CounsellingCase $case, Counsellor $counsellor): void
  {
    $counsellor->loadMissing('user');
    $user = $counsellor->user;
    if ($user === null || empty($user->email)) {
      return;
    }

    $variables = [
      'case_number' => $case->case_number,
      'client_name' => $case->client_name,
      'service_title' => $case->service?->title,
      'admin_name' => $counsellor->display_name ?: $user->name ?: 'Counsellor',
    ];

    try {
      $this->communicationDispatch->dispatchEvent(
        eventKey: 'counseling.counsellor.assigned',
        section: 'counseling',
        variables: $variables,
        context: ['assigned_counsellor_user_id' => $user->id],
        recipientUser: $user,
        recipientEmail: $user->email,
        recipientName: $counsellor->display_name ?: $user->name ?: 'Counsellor',
        related: $case,
        includeRouting: false,
        idempotencyKey: "counseling.counsellor.assigned:{$case->uuid}:{$user->uuid}",
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  public function notifyClientCounsellorAssigned(CounsellingCase $case): void
  {
    $this->notifyClient($case, 'counseling.counsellor.assigned.client', [
      'case_number' => $case->case_number,
      'counsellor_name' => $case->counsellor?->display_name,
    ], 'Counsellor assigned to your case', "counseling.client.counsellor:{$case->uuid}");
  }

  public function notifyMessageReceived(CounsellingCase $case, string $recipientRole, string $preview): void
  {
    if ($recipientRole === 'client') {
      $this->notifyClient($case, 'counseling.message.received', [
        'case_number' => $case->case_number,
        'reason' => $preview,
      ], 'New counselling message', "counseling.message.client:{$case->uuid}:".md5($preview));

      return;
    }

    $case->loadMissing('counsellor.user');
    $user = $case->counsellor?->user;
    if ($user === null || empty($user->email)) {
      return;
    }

    try {
      $this->communicationDispatch->dispatchEvent(
        eventKey: 'counseling.message.received',
        section: 'counseling',
        variables: ['case_number' => $case->case_number, 'reason' => $preview],
        context: ['assigned_counsellor_user_id' => $user->id],
        recipientUser: $user,
        recipientEmail: $user->email,
        recipientName: $case->counsellor?->display_name ?: $user->name ?: 'Counsellor',
        related: $case,
        includeRouting: false,
        idempotencyKey: "counseling.message.counsellor:{$case->uuid}:".md5($preview),
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function notifyClient(
    CounsellingCase $case,
    string $eventKey,
    array $payload,
    string $inAppTitle,
    string $idempotencyKey,
  ): void {
    $case->loadMissing(['user.member', 'member']);
    $user = $case->user;
    $email = $case->client_email ?: $user?->email;
    $name = $case->client_name ?: ($user?->display_name ?: $user?->name ?: 'Client');

    if (! is_string($email) || $email === '') {
      return;
    }

    try {
      $this->communicationDispatch->dispatchEvent(
        eventKey: $eventKey,
        section: 'counseling',
        variables: array_merge($payload, [
          'applicant_name' => $name,
          'member_name' => $name,
          'email' => $email,
          'in_app_title' => $inAppTitle,
          'in_app_body' => (string) ($payload['service_title'] ?? $payload['case_number'] ?? $inAppTitle),
        ]),
        context: [
          'assigned_counsellor_user_id' => $case->counsellor?->user_id,
        ],
        recipientUser: $user,
        recipientEmail: $email,
        recipientName: $name,
        related: $case,
        includeRouting: false,
        idempotencyKey: $idempotencyKey,
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  /**
   * @param  array<string, mixed>  $variables
   */
  private function dispatchAdmin(CounsellingCase $case, string $eventKey, array $variables, string $idempotencyKey): void
  {
    try {
      $this->communicationDispatch->dispatchEvent(
        eventKey: $eventKey,
        section: 'counseling',
        variables: $variables,
        context: ['assigned_counsellor_user_id' => $case->counsellor?->user_id],
        related: $case,
        includeRouting: true,
        idempotencyKey: $idempotencyKey,
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
