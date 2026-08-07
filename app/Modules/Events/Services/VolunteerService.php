<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\VolunteerAssignmentStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Models\EventVolunteerRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VolunteerService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function listRoles(?int $eventId = null, array $filters = []): LengthAwarePaginator
  {
    $query = EventVolunteerRole::query()->with('event')->orderBy('sort_order');

    if ($eventId !== null) {
      $query->where('event_id', $eventId);
    }

    if (isset($filters['is_active'])) {
      $query->where('is_active', (bool) $filters['is_active']);
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function createRole(Event $event, array $data): EventVolunteerRole
  {
    $data['event_id'] = $event->id;
    $data['slug'] ??= Str::slug($data['name']);

    return EventVolunteerRole::query()->create($data)->fresh();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateRole(EventVolunteerRole $role, array $data): EventVolunteerRole
  {
    if (isset($data['name']) && empty($data['slug'])) {
      $data['slug'] = Str::slug($data['name']);
    }

    $role->fill($data);
    $role->save();

    return $role->fresh();
  }

  public function deleteRole(EventVolunteerRole $role): void
  {
    $role->delete();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function listAssignments(array $filters = []): LengthAwarePaginator
  {
    $query = EventVolunteerAssignment::query()
      ->with(['event', 'role', 'member', 'registration'])
      ->orderByDesc('created_at');

    foreach (['event_id', 'role_id', 'member_id', 'registration_id', 'status'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    if (! empty($filters['volunteer_interest'])) {
      $query->whereHas('registration', fn ($q) => $q->where('volunteer_interest', true));
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function interestedRegistrations(int $eventId): array
  {
    return EventRegistration::query()
      ->with(['member'])
      ->where('event_id', $eventId)
      ->where('volunteer_interest', true)
      ->get()
      ->map(fn (EventRegistration $registration): array => [
        'id' => $registration->uuid,
        'registration_number' => $registration->registration_number,
        'name' => $registration->contactName(),
        'email' => $registration->contactEmail(),
        'phone' => $registration->contactPhone(),
        'is_member' => $registration->member_id !== null,
      ])
      ->values()
      ->all();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function assign(EventRegistration $registration, array $data, User $actor): EventVolunteerAssignment
  {
    return DB::transaction(function () use ($registration, $data, $actor): EventVolunteerAssignment {
      $roleId = (int) $data['role_id'];
      $role = EventVolunteerRole::query()->findOrFail($roleId);

      if ($role->event_id !== $registration->event_id) {
        throw ValidationException::withMessages(['role_id' => ['Role must belong to the same event.']]);
      }

      if ($role->slots !== null) {
        $filled = EventVolunteerAssignment::query()
          ->where('role_id', $role->id)
          ->whereIn('status', [
            VolunteerAssignmentStatus::Assigned->value,
            VolunteerAssignmentStatus::Confirmed->value,
            VolunteerAssignmentStatus::Completed->value,
          ])
          ->count();

        if ($filled >= $role->slots) {
          throw ValidationException::withMessages(['role_id' => ['This volunteer role has no available slots.']]);
        }
      }

      return EventVolunteerAssignment::query()->create([
        'event_id' => $registration->event_id,
        'registration_id' => $registration->id,
        'member_id' => $registration->member_id,
        'role_id' => $role->id,
        'status' => $data['status'] ?? VolunteerAssignmentStatus::Assigned->value,
        'shift_starts_at' => $data['shift_starts_at'] ?? null,
        'shift_ends_at' => $data['shift_ends_at'] ?? null,
        'notes' => $data['notes'] ?? null,
        'assigned_by_user_id' => $actor->id,
      ])->fresh(['event', 'role', 'member', 'registration']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function updateAssignment(EventVolunteerAssignment $assignment, array $data, User $actor): EventVolunteerAssignment
  {
    $assignment->fill(collect($data)->only([
      'status', 'shift_starts_at', 'shift_ends_at', 'notes', 'performance_score',
    ])->all());

    if (($data['status'] ?? null) === VolunteerAssignmentStatus::Completed->value) {
      $assignment->completed_at = now();
    }

    $assignment->save();

    return $assignment->fresh(['event', 'role', 'member', 'registration']);
  }

  public function deleteAssignment(EventVolunteerAssignment $assignment): void
  {
    $assignment->delete();
  }
}
