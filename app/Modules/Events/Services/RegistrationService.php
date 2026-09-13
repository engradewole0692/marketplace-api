<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\TimelineEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationQuestion;
use App\Modules\Events\Models\EventRegistrationSequence;
use App\Modules\Events\Models\EventRegistrationStatusTransition;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Support\EventRegistrantResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class RegistrationService implements ServiceContract
{
  public function __construct(
    private readonly RegistrationAuditService $auditService,
    private readonly RegistrationTimelineService $timelineService,
    private readonly EventRegistrantResolver $registrantResolver,
    private readonly PersonIdentityService $personIdentityService,
    private readonly CheckInTokenService $checkInTokenService,
    private readonly EventPaymentService $eventPaymentService,
    private readonly NotificationService $notificationService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = [], ?User $actor = null): LengthAwarePaginator
  {
    $query = EventRegistration::query()->with(['event', 'member', 'person.country', 'payments'])->orderByDesc('created_at');
    app(EventAuthorizationService::class)->restrictEventOwnedQuery($query, $actor);

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
          ->orWhereHas('person', function ($personQuery) use ($like): void {
            $personQuery->where('email', 'like', $like)
              ->orWhere('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('display_name', 'like', $like)
              ->orWhere('person_no', 'like', $like)
              ->orWhere('phone', 'like', $like);
          })
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

    if (! empty($filters['person_id'])) {
      $personId = Person::query()
        ->where('uuid', $filters['person_id'])
        ->orWhere('id', $filters['person_id'])
        ->value('id');
      if ($personId !== null) {
        $query->where('person_id', $personId);
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
      $event = Event::query()->findOrFail($eventId);
      $formConfig = app(RegistrationFormConfigService::class);
      $extracted = $formConfig->extractPersistableFields($event, $data);
      $staffContext = (bool) ($data['_staff'] ?? false);

      $identity = $this->personIdentityService->resolve($data, $actor, $staffContext);
      $person = $identity['person'];
      $member = $identity['member'];
      $guest = $this->guestSnapshot($data, $actor, $person);

      $existing = EventRegistration::query()
        ->where('event_id', $eventId)
        ->where('person_id', $person->id)
        ->first();

      if ($existing === null && $member !== null) {
        $existing = EventRegistration::query()
          ->where('event_id', $eventId)
          ->where('member_id', $member->id)
          ->first();
      }

      if ($existing === null && ! empty($guest['guest_email'])) {
        $existing = EventRegistration::query()
          ->where('event_id', $eventId)
          ->where(function ($query) use ($guest): void {
            $query->where('guest_email', $guest['guest_email'])
              ->orWhereHas('person', fn ($personQuery) => $personQuery->whereRaw('LOWER(email) = ?', [strtolower((string) $guest['guest_email'])]));
          })
          ->first();
      }

      if ($existing !== null) {
        if ($existing->person_id === null) {
          $existing->person_id = $person->id;
          $existing->member_id = $existing->member_id ?: $member?->id;
          $existing->save();
        }

        $refreshed = $this->refreshRegistration($existing, $data, $actor, $extracted);
        $this->syncRequestedServices($refreshed);

        return [
          'registration' => $refreshed,
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
      $person->loadMissing('member');
      $metadata['membership_at_registration'] = \App\Modules\Events\Support\MembershipClassification::forPerson($person);

      $registration = EventRegistration::query()->create([
        ...$extracted['attributes'],
        'event_id' => $eventId,
        'member_id' => $member?->id,
        'person_id' => $person->id,
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
      $this->syncRequestedServices($registration);
      $this->applyPhase3Services($registration, $data, $actor);

      $this->auditService->record(
        RegistrationAuditEventType::RegistrationCreated,
        $registration,
        $actor,
        null,
        ['registration_number' => $registration->registration_number, 'person_id' => $person->uuid],
      );
      $this->auditService->record(
        RegistrationAuditEventType::IdentityResolved,
        $registration,
        $actor,
        null,
        [
          'person_id' => $person->uuid,
          'person_no' => $person->person_no,
          'created_person' => $identity['created'],
          'match_reasons' => $identity['match_reasons'],
        ],
      );
      $this->timelineService->record($registration, TimelineEventType::RegistrationSubmitted, 'Event registration submitted.', $actor);

      $registration->loadMissing('event');

      if ($registration->event?->check_in_enabled) {
        $this->checkInTokenService->issue($registration, null, $actor);
      }

      if ($registration->event?->is_paid) {
        $this->eventPaymentService->ensurePendingPayment($registration);
      }

      return [
        'registration' => $registration->fresh(['event', 'member', 'person.country', 'services', 'payments']),
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
    $this->syncRequestedServices($registration);
    $this->applyPhase3Services($registration, $data, $actor);

    $this->auditService->record(
      RegistrationAuditEventType::RegistrationUpdated,
      $registration,
      $actor,
      $old,
      $registration->only(array_keys($old)),
      ['source' => $data['source'] ?? 'public_form'],
    );
    $this->timelineService->record($registration, TimelineEventType::RegistrationSubmitted, 'Event registration updated.', $actor);

    return $registration->fresh(['event', 'member', 'person.country', 'services']);
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

      return $registration->fresh(['event', 'member', 'person']);
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
   * @param  array<string, mixed>  $data
   * @return array{guest_name: ?string, guest_email: ?string, guest_phone: ?string}
   */
  private function guestSnapshot(array $data, ?User $actor, Person $person): array
  {
    $registrant = is_array($data['registrant'] ?? null) ? $data['registrant'] : [];
    $name = isset($registrant['name']) ? trim((string) $registrant['name']) : '';
    if ($name === '' && (isset($registrant['first_name']) || isset($registrant['last_name']))) {
      $name = trim(trim((string) ($registrant['first_name'] ?? '')).' '.trim((string) ($registrant['last_name'] ?? '')));
    }
    $email = isset($registrant['email']) ? strtolower(trim((string) $registrant['email'])) : '';
    $phone = isset($registrant['phone']) ? trim((string) $registrant['phone']) : '';

    return [
      'guest_name' => $name !== '' ? $name : $person->fullName(),
      'guest_email' => $email !== '' ? $email : $person->email,
      'guest_phone' => $phone !== '' ? $phone : $person->phone,
    ];
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function applyPhase3Services(EventRegistration $registration, array $data, ?User $actor): void
  {
    $registration->loadMissing('event');
    $event = $registration->event;
    $accommodation = is_array($data['accommodation'] ?? null) ? $data['accommodation'] : [];
    if (! empty($accommodation['option_id'])) {
      app(AccommodationService::class)->requestForRegistration($registration, $accommodation, $actor);
    }

    $trips = is_array($data['transport_trips'] ?? null) ? $data['transport_trips'] : [];
    if ($trips !== []) {
      foreach ($trips as $trip) {
        if (! is_array($trip) || (empty($trip['option_id']) && empty($trip['route']))) {
          continue;
        }
        app(TransportService::class)->requestTrip($registration, $trip, $actor);
      }
    }

    $travel = is_array($data['travel'] ?? null) ? $data['travel'] : [];
    $wantsTravel = $this->truthy($data['travel_assistance'] ?? null)
      || $travel !== [];
    if ($wantsTravel && (! $event || $event->travel_assistance_enabled)) {
      app(TravelAssistanceService::class)->request($registration, $travel, $actor);
    }
  }

  private function syncRequestedServices(EventRegistration $registration): void
  {
    $registration->refresh();

    if ($registration->accommodation_required) {
      $this->ensureService(
        $registration,
        EventRegServiceType::Accommodation,
        EventRegServiceStatus::Requested,
        $this->accommodationDetails($registration),
      );
    }

    if ($registration->airport_pickup_required) {
      $this->ensureService(
        $registration,
        EventRegServiceType::Transport,
        EventRegServiceStatus::Requested,
        ['service' => 'airport_pickup'],
      );
    }

    $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
    $travelRequested = $this->truthy($profile['travel_assistance'] ?? $profile['travel_required'] ?? null);
    if ($travelRequested) {
      $this->ensureService(
        $registration,
        EventRegServiceType::Travel,
        EventRegServiceStatus::Requested,
        array_filter([
          'origin' => $profile['travel_origin'] ?? $profile['origin'] ?? null,
          'destination' => $profile['travel_destination'] ?? $profile['destination'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''),
      );
    }
  }

  /**
   * @param  array<string, mixed>|null  $details
   */
  private function ensureService(
    EventRegistration $registration,
    EventRegServiceType $type,
    EventRegServiceStatus $status,
    ?array $details = null,
  ): void {
    $service = EventRegService::query()->firstOrNew([
      'registration_id' => $registration->id,
      'type' => $type->value,
    ]);

    if (! $service->exists) {
      $service->status = $status;
      $service->details = $details;
      $service->save();

      return;
    }

    $current = $service->status instanceof EventRegServiceStatus
      ? $service->status
      : EventRegServiceStatus::tryFrom((string) $service->status);

    if ($current !== null && $current->isConfirmed()) {
      return;
    }

    if ($details !== null && $details !== []) {
      $service->details = array_merge(is_array($service->details) ? $service->details : [], $details);
      $service->save();
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function accommodationDetails(EventRegistration $registration): array
  {
    $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];

    return array_filter([
      'type' => $profile['accommodation_type'] ?? null,
      'arrival_date' => $registration->arrival_date?->toDateString(),
      'departure_date' => $registration->departure_date?->toDateString(),
    ], fn ($value) => $value !== null && $value !== '');
  }

  private function truthy(mixed $value): bool
  {
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return (bool) $value;
  }

  /**
   * @return array{members: list<array<string, mixed>>, registrations: list<array<string, mixed>>, persons: list<array<string, mixed>>}
   */
  public function searchRegistrants(string $query, ?int $eventId = null, int $limit = 10, ?User $actor = null): array
  {
    $term = trim($query);
    if ($term === '') {
      return ['members' => [], 'registrations' => [], 'persons' => []];
    }

    $like = '%'.$term.'%';
    $persons = $this->personIdentityService->search($term, $limit);

    $members = Member::query()
      ->with('person')
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
      ->map(function (Member $member): array {
        $person = $member->person_id ? $member->person : $this->personIdentityService->ensureForMember($member);

        return [
          'id' => $member->uuid,
          'person_id' => $person->uuid,
          'person_no' => $person->person_no,
          'member_id' => $member->uuid,
          'name' => $member->fullName(),
          'email' => $member->email,
          'phone' => $member->phone,
          'is_member' => true,
          'match_reasons' => ['member'],
        ];
      })
      ->values()
      ->all();

    $registrationQuery = EventRegistration::query()->with(['event', 'member', 'person']);
    app(EventAuthorizationService::class)->restrictEventOwnedQuery($registrationQuery, $actor);

    if ($eventId !== null) {
      $registrationQuery->where('event_id', $eventId);
    }

    $registrations = $registrationQuery
      ->where(function ($builder) use ($like): void {
        $builder->where('registration_number', 'like', $like)
          ->orWhere('guest_email', 'like', $like)
          ->orWhere('guest_phone', 'like', $like)
          ->orWhere('guest_name', 'like', $like)
          ->orWhereHas('person', function ($personQuery) use ($like): void {
            $personQuery->where('email', 'like', $like)
              ->orWhere('phone', 'like', $like)
              ->orWhere('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('display_name', 'like', $like)
              ->orWhere('person_no', 'like', $like);
          })
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
        'person_id' => $registration->person?->uuid,
        'person_no' => $registration->person?->person_no,
        'event_id' => $registration->event?->uuid,
        'event_title' => $registration->event?->title,
        'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : $registration->status,
      ])
      ->values()
      ->all();

    return [
      'persons' => $persons,
      'members' => $members,
      'registrations' => $registrations,
    ];
  }
}
