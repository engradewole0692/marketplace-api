<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Enums;

enum CaseStatus: string
{
  case Submitted = 'submitted';
  case PendingReview = 'pending_review';
  case UnderReview = 'under_review';
  case AwaitingClient = 'awaiting_client';
  case Assigned = 'assigned';
  case AppointmentScheduled = 'appointment_scheduled';
  case WaitingPayment = 'waiting_payment';
  case PaymentConfirmed = 'payment_confirmed';
  case InProgress = 'in_progress';
  case FollowUpRequired = 'follow_up_required';
  case Completed = 'completed';
  case Closed = 'closed';
  case Cancelled = 'cancelled';
  case Rejected = 'rejected';

  /** @deprecated legacy aliases — normalize() maps these */
  case Pending = 'pending';
  case Scheduled = 'scheduled';
  case Confirmed = 'confirmed';
  case Session1 = 'session_1';
  case Session2 = 'session_2';
  case Session3 = 'session_3';
  case OnHold = 'on_hold';
  case Escalated = 'escalated';
  case AwaitingResponse = 'awaiting_response';

  public function label(): string
  {
    return match ($this->normalize()) {
      self::Submitted => 'Submitted',
      self::PendingReview => 'Pending Review',
      self::UnderReview => 'Under Review',
      self::AwaitingClient => 'Awaiting Client',
      self::Assigned => 'Assigned',
      self::AppointmentScheduled => 'Appointment Scheduled',
      self::WaitingPayment => 'Awaiting Payment',
      self::PaymentConfirmed => 'Payment Confirmed',
      self::InProgress => 'Session In Progress',
      self::FollowUpRequired => 'Follow-up Required',
      self::Completed => 'Completed',
      self::Closed => 'Closed',
      self::Cancelled => 'Cancelled',
      self::Rejected => 'Rejected',
      default => str_replace('_', ' ', ucfirst($this->value)),
    };
  }

  public function normalize(): self
  {
    return match ($this) {
      self::Pending => self::Submitted,
      self::AwaitingResponse, self::OnHold => self::AwaitingClient,
      self::Scheduled, self::Confirmed => self::AppointmentScheduled,
      self::Session1, self::Session2, self::Session3, self::Escalated => self::InProgress,
      default => $this,
    };
  }

  /** @return list<self> */
  public function allowedTransitions(): array
  {
    return match ($this->normalize()) {
      self::Submitted => [
        self::PendingReview,
        self::UnderReview,
        self::AwaitingClient,
        self::Rejected,
        self::Cancelled,
      ],
      self::PendingReview => [
        self::UnderReview,
        self::AwaitingClient,
        self::Assigned,
        self::Rejected,
        self::Cancelled,
      ],
      self::UnderReview => [
        self::Assigned,
        self::AwaitingClient,
        self::WaitingPayment,
        self::Rejected,
        self::Cancelled,
      ],
      self::AwaitingClient => [
        self::PendingReview,
        self::UnderReview,
        self::Assigned,
        self::Cancelled,
      ],
      self::WaitingPayment => [
        self::PaymentConfirmed,
        self::UnderReview,
        self::Assigned,
        self::Cancelled,
      ],
      self::PaymentConfirmed => [
        self::Assigned,
        self::AppointmentScheduled,
        self::InProgress,
        self::Cancelled,
      ],
      self::Assigned => [
        self::AppointmentScheduled,
        self::InProgress,
        self::AwaitingClient,
        self::WaitingPayment,
        self::Cancelled,
      ],
      self::AppointmentScheduled => [
        self::InProgress,
        self::AwaitingClient,
        self::WaitingPayment,
        self::FollowUpRequired,
        self::Cancelled,
      ],
      self::InProgress => [
        self::FollowUpRequired,
        self::AppointmentScheduled,
        self::Completed,
        self::AwaitingClient,
        self::Cancelled,
      ],
      self::FollowUpRequired => [
        self::AppointmentScheduled,
        self::InProgress,
        self::Completed,
        self::Cancelled,
      ],
      self::Completed => [self::Closed, self::FollowUpRequired],
      self::Closed, self::Cancelled, self::Rejected => [],
      default => [],
    };
  }

  public function canTransitionTo(self $next): bool
  {
    $normalizedNext = $next->normalize();

    return in_array($normalizedNext, $this->allowedTransitions(), true)
      || in_array($next, $this->allowedTransitions(), true);
  }
}
