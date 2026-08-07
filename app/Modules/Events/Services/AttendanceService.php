<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\AttendanceStatus;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceService implements ServiceContract
{
  public function __construct(
    private readonly RegistrationAuditService $registrationAuditService,
    private readonly CheckInTokenService $tokenService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = EventAttendanceHistory::query()->with(['event', 'member', 'registration'])->orderByDesc('occurred_at');

    foreach (['event_id', 'member_id', 'registration_id', 'status'] as $field) {
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

    if ($registration->status === RegistrationStatus::CheckedIn && ! $force) {
      throw ValidationException::withMessages([
        'registration' => ['This registration is already checked in.'],
      ]);
    }

    if ($registration->checkIns()->exists() && ! $force) {
      throw ValidationException::withMessages([
        'registration' => ['A check-in record already exists for this registration.'],
      ]);
    }

    return DB::transaction(function () use ($registration, $data, $actor): EventCheckIn {
      $checkedInAt = $data['checked_in_at'] ?? now();

      $checkIn = EventCheckIn::query()->create([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $data['event_session_id'] ?? null,
        'checked_in_by_user_id' => $actor->id,
        'method' => $data['method'] ?? CheckInMethod::Manual,
        'checked_in_at' => $checkedInAt,
        'notes' => $data['notes'] ?? null,
      ]);

      EventAttendanceHistory::query()->create([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $data['event_session_id'] ?? null,
        'status' => AttendanceStatus::Present,
        'source' => 'check_in',
        'occurred_at' => $checkedInAt,
        'recorded_by_user_id' => $actor->id,
        'notes' => $data['notes'] ?? null,
      ]);

      $registration->status = RegistrationStatus::CheckedIn;
      $registration->updated_by_user_id = $actor->id;
      $registration->save();

      $this->registrationAuditService->record(RegistrationAuditEventType::CheckInRecorded, $registration, $actor, null, ['checked_in_at' => $checkedInAt]);

      return $checkIn->fresh(['event', 'member', 'registration']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkOut(EventRegistration $registration, array $data, User $actor): EventAttendanceHistory
  {
    if ($registration->status !== RegistrationStatus::CheckedIn) {
      throw ValidationException::withMessages([
        'registration' => ['Only checked-in registrations can be checked out.'],
      ]);
    }

    if ($registration->attendanceHistories()->where('status', AttendanceStatus::CheckedOut)->exists()) {
      throw ValidationException::withMessages([
        'registration' => ['This registration has already been checked out.'],
      ]);
    }

    return DB::transaction(function () use ($registration, $data, $actor): EventAttendanceHistory {
      $checkedOutAt = $data['checked_out_at'] ?? now();

      $history = EventAttendanceHistory::query()->create([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'event_session_id' => $data['event_session_id'] ?? null,
        'status' => AttendanceStatus::CheckedOut,
        'source' => 'check_out',
        'occurred_at' => $checkedOutAt,
        'recorded_by_user_id' => $actor->id,
        'notes' => $data['notes'] ?? null,
      ]);

      $registration->status = RegistrationStatus::Attended;
      $registration->updated_by_user_id = $actor->id;
      $registration->save();

      $this->registrationAuditService->record(
        RegistrationAuditEventType::CheckOutRecorded,
        $registration,
        $actor,
        null,
        ['checked_out_at' => $checkedOutAt],
      );

      return $history->fresh(['event', 'member', 'registration']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkInByToken(string $plaintext, array $data, User $actor): EventCheckIn
  {
    $token = $this->tokenService->validate($plaintext);
    $token->last_used_at = now();
    $token->save();

    $registration = EventRegistration::query()->findOrFail($token->registration_id);
    $data['method'] = CheckInMethod::Qr;

    return $this->checkIn($registration, $data, $actor);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function checkOutByToken(string $plaintext, array $data, User $actor): EventAttendanceHistory
  {
    $token = $this->tokenService->validate($plaintext);
    $token->last_used_at = now();
    $token->save();

    $registration = EventRegistration::query()->findOrFail($token->registration_id);

    return $this->checkOut($registration, $data, $actor);
  }
}
