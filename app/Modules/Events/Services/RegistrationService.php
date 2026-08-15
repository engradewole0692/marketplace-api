<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\TimelineEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationQuestion;
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

    if (! empty($filters['event_id'])) {
      $eventId = Event::query()
        ->where('uuid', $filters['event_id'])
        ->orWhere('id', $filters['event_id'])
        ->value('id');
      if ($eventId !== null) {
        $query->where('event_id', $eventId);
      }
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $like = '%'.$search.'%';
      $query->where(function ($builder) use ($like): void {
        $builder->where('registration_number', 'like', $like)
          ->orWhere('guest_email', 'like', $like)
          ->orWhere('guest_name', 'like', $like)
          ->orWhere('guest_phone', 'like', $like)
          ->orWhereHas('member', function ($memberQuery) use ($like): void {
            $memberQuery->where('email', 'like', $like)
              ->orWhere('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('display_name', 'like', $like);
          });
      });
    }

    foreach (['ministry_id', 'country_id', 'region_id'] as $orgField) {
      if (! empty($filters[$orgField])) {
        $value = $filters[$orgField];
        $query->whereHas('event', function ($eventQuery) use ($orgField, $value): void {
          if ($orgField === 'ministry_id') {
            $eventQuery->where(function ($q) use ($value): void {
              $q->where('ministry_id', $value)
                ->orWhereHas('ministry', fn ($m) => $m->where('uuid', $value));
            });
          } elseif ($orgField === 'country_id') {
            $eventQuery->where(function ($q) use ($value): void {
              $q->where('country_id', $value)
                ->orWhereHas('country', fn ($c) => $c->where('uuid', $value));
            });
          } else {
            $eventQuery->where(function ($q) use ($value): void {
              $q->where('region_id', $value)
                ->orWhereHas('region', fn ($r) => $r->where('uuid', $value));
            });
          }
        });
      }
    }

    if (! empty($filters['member_id'])) {
      $query->where('member_id', $filters['member_id']);
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
      $event = Event::query()->findOrFail($eventId);
      $formConfig = app(RegistrationFormConfigService::class);
      $extracted = $formConfig->extractPersistableFields($event, $data);

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

      // Also prevent guest duplicates by phone when email is absent.
      $existing = $member !== null
        ? EventRegistration::query()->where('event_id', $eventId)->where('member_id', $member->id)->first()
        : null;

      if ($existing === null && $member === null && ! empty($guest['guest_email'])) {
        $existing = EventRegistration::query()
          ->where('event_id', $eventId)
          ->whereNull('member_id')
          ->where('guest_email', $guest['guest_email'])
          ->first();
      }

      if ($existing === null && $member === null && ! empty($guest['guest_phone'])) {
        $existing = EventRegistration::query()
          ->where('event_id', $eventId)
          ->whereNull('member_id')
          ->where('guest_phone', $guest['guest_phone'])
          ->first();
      }

      if ($existing !== null) {
        return [
          'registration' => $this->refreshRegistration($existing, $data, $actor, $extracted),
          'created' => false,
        ];
      }

      $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
      if ($extracted['profile'] !== []) {
        $metadata['profile'] = array_merge(
          is_array($metadata['profile'] ?? null) ? $metadata['profile'] : [],
          $extracted['profile'],
        );
      }

      $registration = EventRegistration::query()->create([
        ...$extracted['attributes'],
        'event_id' => $eventId,
        'member_id' => $member?->id,
        'guest_name' => $member ? null : $guest['guest_name'],
        'guest_email' => $member ? null : $guest['guest_email'],
        'guest_phone' => $member ? null : $guest['guest_phone'],
        'registration_number' => $this->nextRegistrationNumber($eventId),
        'status' => RegistrationStatus::Submitted,
        'source' => $data['source'] ?? 'public_form',
        'consent_accepted' => (bool) ($data['consent_accepted'] ?? false),
        'consent_accepted_at' => ($data['consent_accepted'] ?? false) ? now() : null,
        'submitted_at' => now(),
        'created_by_user_id' => $actor?->id,
        'updated_by_user_id' => $actor?->id,
        'metadata' => $metadata === [] ? null : $metadata,
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
   * @param  array{attributes: array<string, mixed>, profile: array<string, mixed>}|null  $extracted
   */
  private function refreshRegistration(
    EventRegistration $registration,
    array $data,
    ?User $actor,
    ?array $extracted = null,
  ): EventRegistration {
    $extracted ??= app(RegistrationFormConfigService::class)->extractPersistableFields(
      $registration->event ?? Event::query()->findOrFail($registration->event_id),
      $data,
    );

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
      'seat_reservation',
      'metadata',
    ]);

    $registration->fill($extracted['attributes']);

    if ($extracted['profile'] !== []) {
      $metadata = is_array($registration->metadata) ? $registration->metadata : [];
      $metadata['profile'] = array_merge(
        is_array($metadata['profile'] ?? null) ? $metadata['profile'] : [],
        $extracted['profile'],
      );
      $registration->metadata = $metadata;
    }

    $registration->consent_accepted = (bool) ($data['consent_accepted'] ?? $registration->consent_accepted);
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
      ['source' => $data['source'] ?? 'public_form'],
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

    $questionIdsByUuid = EventRegistrationQuestion::query()
      ->where('event_id', $registration->event_id)
      ->whereIn('uuid', collect(array_keys($answers))->filter(fn ($key) => ! is_numeric($key))->values())
      ->pluck('id', 'uuid');

    foreach ($answers as $questionKey => $answer) {
      $questionId = is_numeric($questionKey)
        ? (int) $questionKey
        : $questionIdsByUuid->get((string) $questionKey);

      if ($questionId === null) {
        continue;
      }

      $registration->answers()->create([
        'question_id' => $questionId,
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

  /**
   * @return array{members: list<array<string, mixed>>, registrations: list<array<string, mixed>>}
   */
  public function searchRegistrants(string $query, ?int $eventId = null, int $limit = 10): array
  {
    $term = trim($query);
    if ($term === '') {
      return ['members' => [], 'registrations' => []];
    }

    $like = '%'.$term.'%';

    $members = Member::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('email', 'like', $like)
          ->orWhere('phone', 'like', $like)
          ->orWhere('alternate_phone', 'like', $like)
          ->orWhere('first_name', 'like', $like)
          ->orWhere('last_name', 'like', $like)
          ->orWhere('display_name', 'like', $like)
          ->orWhere('membership_number', 'like', $like);
      })
      ->orderBy('first_name')
      ->limit($limit)
      ->get()
      ->map(fn (Member $member): array => [
        'id' => $member->uuid,
        'member_id' => $member->id,
        'name' => $member->fullName(),
        'email' => $member->email,
        'phone' => $member->phone,
        'is_member' => true,
      ])
      ->values()
      ->all();

    $registrationQuery = EventRegistration::query()->with(['event', 'member']);

    if ($eventId !== null) {
      $registrationQuery->where('event_id', $eventId);
    }

    $registrations = $registrationQuery
      ->where(function ($builder) use ($like): void {
        $builder->where('registration_number', 'like', $like)
          ->orWhere('guest_email', 'like', $like)
          ->orWhere('guest_phone', 'like', $like)
          ->orWhere('guest_name', 'like', $like)
          ->orWhereHas('member', function ($memberQuery) use ($like): void {
            $memberQuery->where('email', 'like', $like)
              ->orWhere('phone', 'like', $like)
              ->orWhere('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('display_name', 'like', $like);
          });
      })
      ->orderByDesc('created_at')
      ->limit($limit)
      ->get()
      ->map(fn (EventRegistration $registration): array => [
        'id' => $registration->uuid,
        'registration_id' => $registration->id,
        'registration_number' => $registration->registration_number,
        'name' => $registration->contactName(),
        'email' => $registration->contactEmail(),
        'phone' => $registration->contactPhone(),
        'is_member' => $registration->member_id !== null,
        'member_id' => $registration->member?->uuid,
        'event_id' => $registration->event?->uuid,
        'event_title' => $registration->event?->title,
        'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : $registration->status,
      ])
      ->values()
      ->all();

    return [
      'members' => $members,
      'registrations' => $registrations,
    ];
  }
}
