<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\TimelineEventType;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationSequence;
use App\Modules\Events\Models\EventRegistrationStatusTransition;
use App\Modules\Events\Support\EventRegistrantResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class RegistrationService implements ServiceContract
{
  public function __construct(
    private readonly RegistrationAuditService $auditService,
    private readonly RegistrationTimelineService $timelineService,
    private readonly EventRegistrantResolver $registrantResolver,
    private readonly CheckInTokenService $checkInTokenService,
    private readonly EventPaymentService $eventPaymentService,
    private readonly NotificationService $notificationService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = EventRegistration::query()->with(['event', 'member'])->orderByDesc('created_at');

    foreach (['event_id', 'member_id', 'status'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array{registration: EventRegistration, created: bool}
   */
  public function register(array $data, ?User $actor = null): array
  {
    return DB::transaction(function () use ($data, $actor): array {
      $eventId = (int) $data['event_id'];

      $member = null;
      $guest = ['guest_name' => null, 'guest_email' => null, 'guest_phone' => null];

      if (isset($data['member_id'])) {
        $member = Member::query()->findOrFail($data['member_id']);
      } elseif ($actor !== null) {
        $actor->loadMissing('member');
        $member = $actor->member;
        if ($member === null && isset($data['registrant'])) {
          $resolved = $this->registrantResolver->resolve($data['registrant']);
          $member = $resolved['member'];
          $guest = $resolved['guest'];
        } elseif ($member === null) {
          $guest = [
            'guest_name' => $actor->display_name ?: $actor->name,
            'guest_email' => $actor->email,
            'guest_phone' => null,
          ];
        }
      } elseif (isset($data['registrant'])) {
        $resolved = $this->registrantResolver->resolve($data['registrant']);
        $member = $resolved['member'];
        $guest = $resolved['guest'];
      }

      $existing = $member !== null
        ? EventRegistration::query()->where('event_id', $eventId)->where('member_id', $member->id)->first()
        : null;

      if ($existing !== null) {
        return [
          'registration' => $this->refreshRegistration($existing, $data, $actor),
          'created' => false,
        ];
      }

      $registration = EventRegistration::query()->create([
        ...collect($data)->except(['registrant', 'answers', 'member_id'])->all(),
        'event_id' => $eventId,
        'member_id' => $member?->id,
        'guest_name' => $member ? null : $guest['guest_name'],
        'guest_email' => $member ? null : $guest['guest_email'],
        'guest_phone' => $member ? null : $guest['guest_phone'],
        'registration_number' => $this->nextRegistrationNumber($eventId),
        'status' => RegistrationStatus::Submitted,
        'consent_accepted_at' => now(),
        'submitted_at' => now(),
        'created_by_user_id' => $actor?->id,
        'updated_by_user_id' => $actor?->id,
      ]);

      $this->syncAnswers($registration, $data['answers'] ?? []);

      $this->auditService->record(RegistrationAuditEventType::RegistrationCreated, $registration, $actor, null, ['registration_number' => $registration->registration_number]);
      $this->timelineService->record($registration, TimelineEventType::RegistrationSubmitted, 'Event registration submitted.', $actor);

      $registration->loadMissing('event');

      if ($registration->event?->check_in_enabled) {
        $this->checkInTokenService->issue($registration, null, $actor);
      }

      if ($registration->event?->is_paid) {
        $this->eventPaymentService->ensurePendingPayment($registration);
      }

      return [
        'registration' => $registration->fresh(['event', 'member']),
        'created' => true,
      ];
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function refreshRegistration(EventRegistration $registration, array $data, ?User $actor): EventRegistration
  {
    $old = $registration->only([
      'emergency_contact_name',
      'emergency_contact_phone',
      'emergency_contact_relationship',
      'arrival_date',
      'departure_date',
      'accommodation_required',
      'airport_pickup_required',
      'dietary_requirements',
      'medical_notes',
      'prayer_requests',
      'additional_notes',
    ]);

    $registration->fill(collect($data)->except(['registrant', 'answers', 'member_id', 'event_id'])->all());
    $registration->consent_accepted_at = now();
    $registration->updated_by_user_id = $actor?->id;
    $registration->save();

    $this->syncAnswers($registration, $data['answers'] ?? []);

    $this->auditService->record(
      RegistrationAuditEventType::RegistrationUpdated,
      $registration,
      $actor,
      $old,
      $registration->only(array_keys($old)),
      ['source' => 'public_form'],
    );
    $this->timelineService->record($registration, TimelineEventType::RegistrationSubmitted, 'Event registration updated.', $actor);

    return $registration->fresh(['event', 'member']);
  }

  /**
   * @param  array<int|string, mixed>  $answers
   */
  private function syncAnswers(EventRegistration $registration, array $answers): void
  {
    if ($answers === []) {
      return;
    }

    $registration->answers()->delete();

    foreach ($answers as $questionId => $answer) {
      $registration->answers()->create([
        'question_id' => (int) $questionId,
        'answer_text' => is_scalar($answer) ? (string) $answer : null,
        'answer_json' => is_array($answer) ? $answer : null,
      ]);
    }
  }

  public function transition(EventRegistration $registration, RegistrationStatus $status, User $actor, ?string $reason = null): EventRegistration
  {
    return DB::transaction(function () use ($registration, $status, $actor, $reason): EventRegistration {
      $from = $registration->status instanceof RegistrationStatus
        ? $registration->status
        : RegistrationStatus::from((string) $registration->status);

      $registration->status = $status;
      $registration->updated_by_user_id = $actor->id;

      if ($status === RegistrationStatus::Approved) {
        $registration->approved_at = now();
        $registration->approved_by_user_id = $actor->id;
      }

      if ($status === RegistrationStatus::Cancelled) {
        $registration->cancelled_at = now();
        $registration->cancelled_by_user_id = $actor->id;
      }

      $registration->save();

      EventRegistrationStatusTransition::query()->create([
        'registration_id' => $registration->id,
        'from_status' => $from->value,
        'to_status' => $status->value,
        'actor_id' => $actor->id,
        'reason' => $reason,
      ]);

      $this->auditService->record(RegistrationAuditEventType::StatusChanged, $registration, $actor, ['status' => $from->value], ['status' => $status->value], ['reason' => $reason]);
      $this->timelineService->record($registration, TimelineEventType::StatusChanged, "Registration status changed to {$status->label()}.", $actor, ['reason' => $reason]);

      if ($status === RegistrationStatus::Approved) {
        $registration->loadMissing('event');
        if ($registration->event?->check_in_enabled && ! $registration->checkInToken()->exists()) {
          $this->checkInTokenService->issue($registration, null, $actor);
        }
      }

      if ($status === RegistrationStatus::Cancelled) {
        $this->notificationService->sendRegistrationCancelled($registration->fresh(['event.venue']), $reason);
      }

      return $registration->fresh(['event', 'member']);
    });
  }

  public function delete(EventRegistration $registration, User $actor): void
  {
    DB::transaction(function () use ($registration, $actor): void {
      $this->auditService->record(
        RegistrationAuditEventType::RegistrationDeleted,
        $registration,
        $actor,
        ['id' => $registration->id, 'registration_number' => $registration->registration_number],
        null,
      );

      $registration->answers()->delete();
      $registration->checkIns()->delete();
      $registration->attendanceHistories()->delete();
      $registration->statusTransitions()->delete();
      $registration->timelines()->delete();

      $registration->deleted_by_user_id = $actor->id;
      $registration->save();
      $registration->delete();
    });
  }

  private function nextRegistrationNumber(int $eventId): string
  {
    $sequence = EventRegistrationSequence::query()->firstOrCreate(['event_id' => $eventId]);
    $sequence->increment('last_sequence');

    return 'EVT-'.$eventId.'-'.str_pad((string) $sequence->last_sequence, 6, '0', STR_PAD_LEFT);
  }
}
