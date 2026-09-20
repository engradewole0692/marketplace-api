<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\AttendanceStatus;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventSessionAttendance;
use App\Modules\Events\Support\MembershipClassification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceService implements ServiceContract
{
  public function __construct(
    private readonly RegistrationAuditService $registrationAuditService,
    private readonly CheckInTokenService $tokenService,
    private readonly EventDayService $eventDayService,
    private readonly SessionResolutionService $sessionResolutionService,
    private readonly SeatingService $seatingService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = [], ?User $actor = null): LengthAwarePaginator
  {
    $query = EventAttendanceHistory::query()
      ->with(['event', 'member', 'registration.person', 'day'])
      ->orderByDesc('occurred_at');
    app(EventAuthorizationService::class)->restrictEventOwnedQuery($query, $actor);

    foreach (['event_id', 'member_id', 'registration_id', 'status', 'event_day_id'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkIn(EventRegistration $registration, array $data, User $actor): EventCheckIn
  {
    $force = (bool) ($data['force'] ?? false);
    $registration->loadMissing('event');
    $event = $registration->event;
    if ($event === null) {
      throw ValidationException::withMessages(['event' => ['Registration is missing an event.']]);
    }

    $day = $this->resolveDay($event, $data);
    $this->assertRegistrationActive($registration);
    $session = $this->requireSessionForCheckIn($event, $data);

    return DB::transaction(function () use ($registration, $data, $actor, $force, $event, $day, $session): EventCheckIn {
      if ($session !== null) {
        EventSession::query()->whereKey($session->id)->lockForUpdate()->first();
      }

      $placement = $this->seatingService->place($event, $registration, $session, $day, $data, $actor);
      $previousStatus = null;

      if ($session !== null) {
        $sessionAttendance = EventSessionAttendance::query()->firstOrCreate(
          [
            'registration_id' => $registration->id,
            'event_session_id' => $session->id,
          ],
          [
            'event_id' => $event->id,
            'event_day_id' => $day->id,
            'person_id' => $registration->person_id,
            'member_id' => $registration->member_id,
            'status' => DayAttendanceStatus::NotAttended,
          ],
        );
        $sessionAttendance = EventSessionAttendance::query()->whereKey($sessionAttendance->id)->lockForUpdate()->firstOrFail();
        $previousStatus = $sessionAttendance->status instanceof DayAttendanceStatus
          ? $sessionAttendance->status
          : DayAttendanceStatus::tryFrom((string) $sessionAttendance->status);

        if ($sessionAttendance->isOpen() && ! $force) {
          $this->throwAlreadyCheckedIn($registration, $day, $sessionAttendance->checked_in_at, $session);
        }

        $checkedInAt = $data['checked_in_at'] ?? now();
        $sessionAttendance->fill([
          'event_day_id' => $day->id,
          'person_id' => $registration->person_id,
          'member_id' => $registration->member_id,
          'status' => DayAttendanceStatus::CheckedIn,
          'method' => $data['method'] ?? CheckInMethod::Manual,
          'seating_area' => $placement['area'],
          'counts_toward_seating' => $placement['counts'],
          'capacity_overridden' => $placement['overridden'],
          'override_reason' => $placement['overridden'] ? ($data['override_reason'] ?? 'Authorized capacity override') : null,
          'checked_in_by_user_id' => $actor->id,
          'checked_in_at' => $checkedInAt,
          'checked_out_at' => null,
          'notes' => $data['notes'] ?? $sessionAttendance->notes,
        ]);
        $sessionAttendance->save();
      }

      $dayAttendance = EventDayAttendance::query()->firstOrCreate(
        [
          'registration_id' => $registration->id,
          'event_day_id' => $day->id,
        ],
        [
          'event_id' => $event->id,
          'person_id' => $registration->person_id,
          'member_id' => $registration->member_id,
          'status' => DayAttendanceStatus::NotAttended,
        ],
      );
      $dayAttendance = EventDayAttendance::query()->whereKey($dayAttendance->id)->lockForUpdate()->firstOrFail();

      $status = $dayAttendance->status instanceof DayAttendanceStatus
        ? $dayAttendance->status
        : DayAttendanceStatus::tryFrom((string) $dayAttendance->status);
      $previousStatus ??= $status;

      if ($session === null && $status === DayAttendanceStatus::CheckedIn && ! $force) {
        $this->throwAlreadyCheckedIn($registration, $day, $dayAttendance->checked_in_at);
      }

      $checkedInAt = $data['checked_in_at'] ?? now();

      $dayAttendance->fill([
        'person_id' => $registration->person_id,
        'member_id' => $registration->member_id,
        'status' => DayAttendanceStatus::CheckedIn,
        'method' => $data['method'] ?? CheckInMethod::Manual,
        'checked_in_at' => $dayAttendance->checked_in_at ?? $checkedInAt,
        'checked_out_at' => null,
        'seating_area' => $placement['area']->value,
        'counts_toward_seating' => $placement['counts'],
        'capacity_overridden' => $placement['overridden'],
        'override_reason' => $placement['overridden'] ? ($data['override_reason'] ?? 'Authorized capacity override') : $dayAttendance->override_reason,
        'notes' => $data['notes'] ?? $dayAttendance->notes,
      ]);
      $dayAttendance->save();

      $checkIn = EventCheckIn::query()->create([
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $session?->id,
        'event_day_id' => $day->id,
        'checked_in_by_user_id' => $actor->id,
        'method' => $data['method'] ?? CheckInMethod::Manual,
        'checked_in_at' => $checkedInAt,
        'notes' => $data['notes'] ?? null,
        'seating_area' => $placement['area']->value,
        'counts_toward_seating' => $placement['counts'],
        'metadata' => array_filter([
          'capacity_overridden' => $placement['overridden'] ?: null,
          'override_reason' => $placement['overridden'] ? ($data['override_reason'] ?? 'Authorized capacity override') : null,
        ]),
      ]);

      EventAttendanceHistory::query()->create([
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $session?->id,
        'event_day_id' => $day->id,
        'status' => AttendanceStatus::Present,
        'source' => 'check_in',
        'occurred_at' => $checkedInAt,
        'recorded_by_user_id' => $actor->id,
        'notes' => $data['notes'] ?? null,
      ]);

      $this->syncRegistrationStatus($registration, $actor, RegistrationStatus::CheckedIn);

      $this->registrationAuditService->record(
        RegistrationAuditEventType::CheckInRecorded,
        $registration,
        $actor,
        ['status' => $previousStatus instanceof \BackedEnum ? $previousStatus->value : $previousStatus],
        [
          'checked_in_at' => $checkedInAt,
          'event_day_id' => $day->uuid,
          'day_label' => $day->label,
          'event_session_id' => $session?->uuid,
          'session_title' => $session?->title,
          'seating_area' => $placement['area']->value,
          'counts_toward_seating' => $placement['counts'],
          'capacity_overridden' => $placement['overridden'],
          'method' => ($data['method'] ?? CheckInMethod::Manual) instanceof \BackedEnum
            ? ($data['method'] ?? CheckInMethod::Manual)->value
            : (string) ($data['method'] ?? CheckInMethod::Manual),
        ],
      );

      return $checkIn->fresh(['event', 'member', 'registration.person', 'day', 'session', 'checkedInBy']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkOut(EventRegistration $registration, array $data, User $actor): EventAttendanceHistory
  {
    $registration->loadMissing('event');
    $event = $registration->event;
    if ($event === null) {
      throw ValidationException::withMessages(['event' => ['Registration is missing an event.']]);
    }
    if (! ($event->checkout_enabled ?? true)) {
      throw ValidationException::withMessages([
        'event' => ['Check-out is disabled for this event. Attendance remains recorded from check-in.'],
      ]);
    }

    $day = $this->resolveDay($event, $data);
    $this->assertRegistrationActive($registration);
    $session = $this->sessionForCheckout($event, $data);

    return DB::transaction(function () use ($registration, $data, $actor, $event, $day, $session): EventAttendanceHistory {
      if ($session !== null) {
        $sessionAttendance = EventSessionAttendance::query()
          ->where('registration_id', $registration->id)
          ->where('event_session_id', $session->id)
          ->lockForUpdate()
          ->first();

        if ($sessionAttendance === null || ! $sessionAttendance->isOpen()) {
          if ($sessionAttendance?->checked_out_at) {
            $when = $sessionAttendance->checked_out_at->toDayDateTimeString();
            throw ValidationException::withMessages([
              'registration' => ['Already checked out'.($when ? ' at '.$when : '').'.'],
            ]);
          }
          throw ValidationException::withMessages([
            'registration' => ['Only a checked-in participant can be checked out for '.$session->title.'.'],
          ]);
        }

        $checkedOutAt = $data['checked_out_at'] ?? now();
        $sessionAttendance->status = DayAttendanceStatus::CheckedOut;
        $sessionAttendance->checked_out_at = $checkedOutAt;
        if (! empty($data['notes'])) {
          $sessionAttendance->notes = $data['notes'];
        }
        $sessionAttendance->save();

        $stillInSession = EventSessionAttendance::query()
          ->where('registration_id', $registration->id)
          ->where('status', DayAttendanceStatus::CheckedIn)
          ->whereNull('checked_out_at')
          ->exists();

        if (! $stillInSession) {
          $dayAttendance = EventDayAttendance::query()
            ->where('registration_id', $registration->id)
            ->where('event_day_id', $day->id)
            ->lockForUpdate()
            ->first();
          if ($dayAttendance !== null && $dayAttendance->checked_out_at === null) {
            $dayAttendance->status = DayAttendanceStatus::CheckedOut;
            $dayAttendance->checked_out_at = $checkedOutAt;
            $dayAttendance->save();
          }
        }

        $history = EventAttendanceHistory::query()->create([
          'event_id' => $event->id,
          'registration_id' => $registration->id,
          'member_id' => $registration->member_id,
          'event_session_id' => $session->id,
          'event_day_id' => $day->id,
          'status' => AttendanceStatus::CheckedOut,
          'source' => 'check_out',
          'occurred_at' => $checkedOutAt,
          'recorded_by_user_id' => $actor->id,
          'notes' => $data['notes'] ?? null,
        ]);

        $stillIn = EventDayAttendance::query()
          ->where('registration_id', $registration->id)
          ->where('status', DayAttendanceStatus::CheckedIn)
          ->exists();

        $this->syncRegistrationStatus(
          $registration,
          $actor,
          $stillIn || $stillInSession ? RegistrationStatus::CheckedIn : RegistrationStatus::Attended,
        );

        $this->registrationAuditService->record(
          RegistrationAuditEventType::CheckOutRecorded,
          $registration,
          $actor,
          ['status' => DayAttendanceStatus::CheckedIn->value],
          [
            'checked_out_at' => $checkedOutAt,
            'event_day_id' => $day->uuid,
            'event_session_id' => $session->uuid,
            'day_label' => $day->label,
          ],
        );

        return $history->fresh(['event', 'member', 'registration.person', 'day', 'session']);
      }

      $dayAttendance = EventDayAttendance::query()
        ->where('registration_id', $registration->id)
        ->where('event_day_id', $day->id)
        ->lockForUpdate()
        ->first();

      $status = $dayAttendance?->status instanceof DayAttendanceStatus
        ? $dayAttendance->status
        : DayAttendanceStatus::tryFrom((string) ($dayAttendance?->status ?? ''));

      if ($status !== DayAttendanceStatus::CheckedIn) {
        $legacyStatus = $registration->status instanceof RegistrationStatus
          ? $registration->status
          : RegistrationStatus::tryFrom((string) $registration->status);
        $legacyCheckedIn = $legacyStatus === RegistrationStatus::CheckedIn
          || EventCheckIn::query()->where('registration_id', $registration->id)->exists();

        if (! $legacyCheckedIn) {
          throw ValidationException::withMessages([
            'registration' => ['Only a checked-in participant can be checked out for '.$day->label.'.'],
          ]);
        }

        $dayAttendance = EventDayAttendance::query()->firstOrCreate(
          [
            'registration_id' => $registration->id,
            'event_day_id' => $day->id,
          ],
          [
            'event_id' => $event->id,
            'person_id' => $registration->person_id,
            'member_id' => $registration->member_id,
            'status' => DayAttendanceStatus::CheckedIn,
            'checked_in_at' => now(),
          ],
        );
        $dayAttendance = EventDayAttendance::query()->whereKey($dayAttendance->id)->lockForUpdate()->firstOrFail();
        if ($dayAttendance->status !== DayAttendanceStatus::CheckedIn) {
          $dayAttendance->status = DayAttendanceStatus::CheckedIn;
          $dayAttendance->checked_in_at ??= now();
          $dayAttendance->save();
        }
      }

      if ($dayAttendance === null) {
        throw ValidationException::withMessages([
          'registration' => ['Only a checked-in participant can be checked out for '.$day->label.'.'],
        ]);
      }

      if ($dayAttendance->checked_out_at !== null) {
        $when = $dayAttendance->checked_out_at->toDayDateTimeString();
        throw ValidationException::withMessages([
          'registration' => ['Already checked out'.($when ? ' at '.$when : '').'.'],
        ]);
      }

      $checkedOutAt = $data['checked_out_at'] ?? now();
      $dayAttendance->status = DayAttendanceStatus::CheckedOut;
      $dayAttendance->checked_out_at = $checkedOutAt;
      if (! empty($data['notes'])) {
        $dayAttendance->notes = $data['notes'];
      }
      $dayAttendance->save();

      $history = EventAttendanceHistory::query()->create([
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $data['event_session_id'] ?? null,
        'event_day_id' => $day->id,
        'status' => AttendanceStatus::CheckedOut,
        'source' => 'check_out',
        'occurred_at' => $checkedOutAt,
        'recorded_by_user_id' => $actor->id,
        'notes' => $data['notes'] ?? null,
      ]);

      $stillIn = EventDayAttendance::query()
        ->where('registration_id', $registration->id)
        ->where('status', DayAttendanceStatus::CheckedIn)
        ->exists();

      $this->syncRegistrationStatus(
        $registration,
        $actor,
        $stillIn ? RegistrationStatus::CheckedIn : RegistrationStatus::Attended,
      );

      $this->registrationAuditService->record(
        RegistrationAuditEventType::CheckOutRecorded,
        $registration,
        $actor,
        ['status' => DayAttendanceStatus::CheckedIn->value],
        ['checked_out_at' => $checkedOutAt, 'event_day_id' => $day->uuid, 'day_label' => $day->label],
      );

      return $history->fresh(['event', 'member', 'registration.person', 'day']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkInByToken(string $plaintext, array $data, User $actor): EventCheckIn
  {
    $registration = $this->registrationFromToken($plaintext, $data, $actor, true);
    $data['method'] = CheckInMethod::Qr;

    return $this->checkIn($registration, $data, $actor);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkOutByToken(string $plaintext, array $data, User $actor): EventAttendanceHistory
  {
    $registration = $this->registrationFromToken($plaintext, $data, $actor, true);

    return $this->checkOut($registration, $data, $actor);
  }

  /**
   * Identify a participant from a QR token without recording attendance.
   *
   * @param  array<string, mixed>  $data
   */
  public function lookupByToken(string $plaintext, array $data, User $actor): EventRegistration
  {
    return $this->registrationFromToken($plaintext, $data, $actor, false);
  }

  /**
   * @return array<string, mixed>
   */
  public function summarizeRegistration(EventRegistration $registration): array
  {
    $registration->loadMissing(['event.days', 'dayAttendances.day', 'person.member', 'member']);
    $event = $registration->event;
    $days = $event?->days ?? collect();
    if ($days->isEmpty() && $event !== null) {
      $days = $this->eventDayService->ensureDays($event);
    }

    $byDayId = $registration->dayAttendances->keyBy('event_day_id');
    $rows = [];
    $attended = 0;
    foreach ($days as $day) {
      /** @var EventDayAttendance|null $record */
      $record = $byDayId->get($day->id);
      $status = $record?->status instanceof DayAttendanceStatus
        ? $record->status
        : DayAttendanceStatus::tryFrom((string) ($record?->status ?? DayAttendanceStatus::NotAttended->value))
          ?? DayAttendanceStatus::NotAttended;
      if ($status->countsAsAttended()) {
        $attended++;
      }
      $rows[] = [
        'id' => $day->uuid,
        'day_index' => $day->day_index,
        'label' => $day->label,
        'date' => $day->date?->toDateString(),
        'check_in' => $record?->checked_in_at?->toIso8601String(),
        'check_out' => $record?->checked_out_at?->toIso8601String(),
        'status' => $status->value,
        'status_label' => $status->label(),
        'attended' => $status->countsAsAttended(),
      ];
    }

    $total = max(1, $days->count());

    return [
      'days_total' => $total,
      'days_attended' => $attended,
      'attendance_count' => $attended.'/'.$total,
      'first_check_in' => $registration->dayAttendances->min('checked_in_at'),
      'last_check_out' => $registration->dayAttendances->max('checked_out_at'),
      'days' => $rows,
      'membership' => MembershipClassification::forPerson($registration->person) ?: MembershipClassification::forMember($registration->member),
    ];
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function registrationFromToken(string $plaintext, array $data, User $actor, bool $consume): EventRegistration
  {
    $token = $this->tokenService->validate($plaintext);
    $registration = EventRegistration::query()->with('event')->findOrFail($token->registration_id);
    $event = $registration->event;
    if ($event === null) {
      throw ValidationException::withMessages(['token' => ['This check-in token is not linked to an event.']]);
    }

    app(EventAuthorizationService::class)->assertAccess($actor, $event);
    app(EventAuthorizationService::class)->assertDomain(
      $actor,
      $event,
      \App\Modules\Events\Enums\EventStaffDomain::Operations,
    );

    $this->assertTokenEvent($registration, $data);
    $this->assertRegistrationActive($registration);

    if ($consume) {
      $token->last_used_at = now();
      $token->save();
    }

    $this->registrationAuditService->record(
      RegistrationAuditEventType::QrTokenScanned,
      $registration,
      $actor,
      null,
      [
        'action' => $consume ? 'scan' : 'lookup',
        'event_id' => $event->uuid,
        'event_day_id' => $data['event_day_id'] ?? null,
      ],
    );

    return $registration;
  }

  private function assertRegistrationActive(EventRegistration $registration): void
  {
    $status = $registration->status instanceof RegistrationStatus
      ? $registration->status
      : RegistrationStatus::tryFrom((string) $registration->status);

    if (in_array($status, [RegistrationStatus::Cancelled, RegistrationStatus::Declined], true)) {
      throw ValidationException::withMessages([
        'registration' => ['This registration is not active.'],
      ]);
    }
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function requireSessionForCheckIn(Event $event, array $data): ?EventSession
  {
    $resolution = $this->sessionResolutionService->resolve($event, $data);
    if ($resolution['has_configured_sessions'] === false) {
      return null;
    }

    if ($resolution['status'] === 'resolved') {
      return $resolution['session'];
    }

    throw new ApiException(
      ApiErrorCode::UnprocessableEntity,
      'Multiple/no active sessions detected.',
      [
        'event_session_id' => ['Multiple/no active sessions detected. Which session are you checking this participant into?'],
        'session_resolution' => [$resolution['status']],
        'available_sessions' => $resolution['candidates']
          ->map(fn (EventSession $session): string => $session->uuid)
          ->values()
          ->all(),
      ],
      409,
    );
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function sessionForCheckout(Event $event, array $data): ?EventSession
  {
    $explicit = $this->sessionResolutionService->findExplicit($event, $data['event_session_id'] ?? null);
    if ($explicit !== null) {
      return $explicit;
    }

    $resolution = $this->sessionResolutionService->resolve($event, $data);
    if ($resolution['has_configured_sessions'] === false) {
      return null;
    }

    return $resolution['status'] === 'resolved' ? $resolution['session'] : null;
  }

  private function throwAlreadyCheckedIn(
    EventRegistration $registration,
    EventDay $day,
    mixed $checkedInAt,
    ?EventSession $session = null,
  ): never {
    $operator = EventCheckIn::query()
      ->with('checkedInBy')
      ->where('registration_id', $registration->id)
      ->when($session, fn ($query) => $query->where('event_session_id', $session->id), fn ($query) => $query->where('event_day_id', $day->id))
      ->latest('id')
      ->first();
    $when = $checkedInAt instanceof \Illuminate\Support\Carbon
      ? $checkedInAt->toDayDateTimeString()
      : null;
    $by = $operator?->checkedInBy?->name;

    throw ValidationException::withMessages([
      'registration' => [
        'Already checked in'
        .($when ? ' at '.$when : '')
        .($by ? ' by '.$by : '')
        .'.',
      ],
    ]);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function resolveDay(Event $event, array $data): EventDay
  {
    $uuid = $data['event_day_id'] ?? null;
    if (is_numeric($uuid)) {
      $day = EventDay::query()->where('event_id', $event->id)->where('id', (int) $uuid)->first();
      if ($day !== null) {
        return $day;
      }
    }

    return $this->eventDayService->resolveCurrentDay($event, is_string($uuid) ? $uuid : null);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function assertTokenEvent(EventRegistration $registration, array $data): void
  {
    $expected = $data['event_id'] ?? null;
    if ($expected === null || $expected === '') {
      return;
    }

    $event = $registration->event;
    $matches = is_numeric($expected)
      ? (int) $expected === (int) $registration->event_id
      : ($event?->uuid === $expected);
    if (! $matches) {
      throw ValidationException::withMessages([
        'token' => ['This QR code belongs to a different event.'],
      ]);
    }
  }

  private function syncRegistrationStatus(EventRegistration $registration, User $actor, RegistrationStatus $status): void
  {
    $registration->status = $status;
    $registration->updated_by_user_id = $actor->id;
    $registration->save();
  }
}
