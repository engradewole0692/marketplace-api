<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStaffAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventStaffAssignmentService implements ServiceContract
{
  public function __construct(
    private readonly EventAuditService $auditService,
  ) {}

  /**
   * @return list<EventStaffAssignment>
   */
  public function listForEvent(Event $event): array
  {
    return $event->staffAssignments()
      ->with(['user', 'createdBy'])
      ->orderByDesc('is_active')
      ->orderBy('created_at')
      ->get()
      ->all();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function assign(Event $event, User $user, User $actor, array $data = []): EventStaffAssignment
  {
    return DB::transaction(function () use ($event, $user, $actor, $data): EventStaffAssignment {
      $existing = EventStaffAssignment::query()
        ->where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->first();

      $staffRole = (string) ($data['staff_role'] ?? 'staff');
      if ($staffRole === '') {
        $staffRole = 'staff';
      }
      $department = isset($data['department']) ? trim((string) $data['department']) : null;
      $department = $department === '' ? null : $department;

      if ($existing !== null) {
        $old = [
          'is_active' => $existing->is_active,
          'staff_role' => $existing->staff_role,
        ];
        $existing->fill([
          'staff_role' => $staffRole,
          'department' => $department ?? $existing->department,
          'is_active' => true,
        ]);
        $existing->save();

        $this->auditService->record(
          $old['is_active'] ? EventAuditEventType::StaffUpdated : EventAuditEventType::StaffAssigned,
          $event,
          $actor,
          EventStaffAssignment::class,
          $existing->id,
          $old,
          ['is_active' => true, 'staff_role' => $staffRole, 'user_id' => $user->id],
        );

        return $existing->fresh(['user', 'createdBy', 'event']);
      }

      $assignment = EventStaffAssignment::query()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'staff_role' => $staffRole,
        'department' => $department,
        'is_active' => true,
        'created_by_user_id' => $actor->id,
      ]);

      $this->auditService->record(
        EventAuditEventType::StaffAssigned,
        $event,
        $actor,
        EventStaffAssignment::class,
        $assignment->id,
        null,
        ['user_id' => $user->id, 'staff_role' => $staffRole, 'is_active' => true],
      );

      return $assignment->fresh(['user', 'createdBy', 'event']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(EventStaffAssignment $assignment, array $data, User $actor): EventStaffAssignment
  {
    $old = [
      'is_active' => $assignment->is_active,
      'staff_role' => $assignment->staff_role,
    ];

    if (array_key_exists('staff_role', $data) && $data['staff_role'] !== null && $data['staff_role'] !== '') {
      $assignment->staff_role = (string) $data['staff_role'];
    }
    if (array_key_exists('department', $data)) {
      $assignment->department = $data['department'] !== null && $data['department'] !== ''
        ? trim((string) $data['department'])
        : null;
    }
    if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
      $assignment->is_active = (bool) $data['is_active'];
    }
    $assignment->save();

    $event = $assignment->event ?? Event::query()->find($assignment->event_id);
    $type = $assignment->is_active
      ? EventAuditEventType::StaffUpdated
      : EventAuditEventType::StaffDeactivated;

    $this->auditService->record(
      $type,
      $event,
      $actor,
      EventStaffAssignment::class,
      $assignment->id,
      $old,
      ['is_active' => $assignment->is_active, 'staff_role' => $assignment->staff_role],
    );

    return $assignment->fresh(['user', 'createdBy', 'event']);
  }

  public function deactivate(EventStaffAssignment $assignment, User $actor): EventStaffAssignment
  {
    if (! $assignment->is_active) {
      return $assignment->fresh(['user', 'createdBy', 'event']) ?? $assignment;
    }

    return $this->update($assignment, ['is_active' => false], $actor);
  }

  /**
   * @return list<array{id: string, name: string, email: string}>
   */
  public function searchUsers(string $query, int $limit = 10): array
  {
    $term = trim($query);
    if (strlen($term) < 2) {
      throw ValidationException::withMessages(['q' => ['Search at least 2 characters.']]);
    }

    $like = '%'.$term.'%';

    return User::query()
      ->where(function ($builder) use ($like): void {
        $builder->where('name', 'like', $like)
          ->orWhere('email', 'like', $like);
      })
      ->orderBy('name')
      ->limit($limit)
      ->get(['id', 'uuid', 'name', 'email'])
      ->map(fn (User $user): array => [
        'id' => $user->uuid,
        'name' => (string) $user->name,
        'email' => (string) $user->email,
      ])
      ->all();
  }
}
