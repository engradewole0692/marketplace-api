<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Modules\Counselling\Enums\AppointmentStatus;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Models\CounsellingAppointment;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\Counsellor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CounsellingAppointmentService implements ServiceContract
{
  public function __construct(
    private readonly CounsellingAuditService $auditService,
    private readonly CounsellingNotificationService $notificationService,
  ) {}

  /**
   * @param  array<string, mixed>  $data
   */
  public function schedule(CounsellingCase $case, array $data, ?User $actor = null): CounsellingAppointment
  {
    return DB::transaction(function () use ($case, $data, $actor): CounsellingAppointment {
      $case->loadMissing(['service', 'counsellor']);

      $counsellorId = $case->counsellor_id;
      if (! empty($data['counsellor_id'])) {
        $counsellorId = Counsellor::query()->where('uuid', $data['counsellor_id'])->value('id') ?? $counsellorId;
      }

      $startsAt = Carbon::parse((string) $data['starts_at']);
      $duration = (int) ($data['duration_minutes'] ?? $case->service?->duration_minutes ?? 60);
      $endsAt = isset($data['ends_at'])
        ? Carbon::parse((string) $data['ends_at'])
        : $startsAt->copy()->addMinutes($duration);

      $sessionNumber = (int) ($data['session_number'] ?? ($case->session_count + 1));

      $appointment = CounsellingAppointment::query()->create([
        'case_id' => $case->id,
        'counsellor_id' => $counsellorId,
        'session_number' => $sessionNumber,
        'status' => AppointmentStatus::Scheduled,
        'format' => $data['format'] ?? $case->preferred_format ?? ServiceFormat::Virtual,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => $data['timezone'] ?? $case->timezone ?? 'UTC',
        'meeting_link' => $data['meeting_link'] ?? null,
        'meeting_platform' => $data['meeting_platform'] ?? null,
        'location' => $data['location'] ?? null,
        'notes' => $data['notes'] ?? null,
        'metadata' => $data['metadata'] ?? null,
      ]);

      $case->scheduled_at = $startsAt;
      if (! in_array($case->status, [CaseStatus::Completed, CaseStatus::Cancelled, CaseStatus::Rejected, CaseStatus::Closed], true)) {
        $case->status = CaseStatus::AppointmentScheduled;
      }
      $case->save();

      $this->auditService->record(
        $case,
        $actor,
        'appointment.scheduled',
        'Appointment Scheduled',
        'Session #'.$sessionNumber.' scheduled for '.$startsAt->toDateTimeString(),
        ['appointment_id' => $appointment->uuid],
      );

      $this->notificationService->notifyAppointmentScheduled(
        $case->fresh(['service', 'counsellor.user']),
        $appointment,
      );

      return $appointment->fresh(['counsellor.user']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function reschedule(
    CounsellingAppointment $appointment,
    array $data,
    ?User $actor = null,
  ): CounsellingAppointment {
    if (! $appointment->case?->allow_reschedule) {
      throw new BusinessException('Rescheduling is not allowed for this case.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    return DB::transaction(function () use ($appointment, $data, $actor): CounsellingAppointment {
      $case = $appointment->case;
      $startsAt = Carbon::parse((string) $data['starts_at']);
      $endsAt = isset($data['ends_at'])
        ? Carbon::parse((string) $data['ends_at'])
        : $startsAt->copy()->addMinutes((int) ($appointment->case?->service?->duration_minutes ?? 60));

      $appointment->status = AppointmentStatus::Rescheduled;
      $appointment->save();

      $replacement = CounsellingAppointment::query()->create([
        'case_id' => $appointment->case_id,
        'counsellor_id' => $appointment->counsellor_id,
        'session_number' => $appointment->session_number,
        'status' => AppointmentStatus::Scheduled,
        'format' => $data['format'] ?? $appointment->format,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => $data['timezone'] ?? $appointment->timezone,
        'meeting_link' => $data['meeting_link'] ?? $appointment->meeting_link,
        'meeting_platform' => $data['meeting_platform'] ?? $appointment->meeting_platform,
        'location' => $data['location'] ?? $appointment->location,
        'notes' => $data['notes'] ?? null,
        'metadata' => ['rescheduled_from' => $appointment->uuid],
      ]);

      if ($case !== null) {
        $case->scheduled_at = $startsAt;
        $case->save();

        $this->auditService->record(
          $case,
          $actor,
          'appointment.rescheduled',
          'Appointment rescheduled',
          'Session #'.$appointment->session_number.' moved to '.$startsAt->toDateTimeString(),
          ['appointment_id' => $replacement->uuid, 'previous_appointment_id' => $appointment->uuid],
        );

        $this->notificationService->notifyRescheduled(
          $case->fresh(['service', 'counsellor.user']),
          $replacement,
        );
      }

      return $replacement->fresh(['counsellor.user', 'case.service']);
    });
  }

  public function confirm(CounsellingAppointment $appointment, ?User $actor = null): CounsellingAppointment
  {
    return DB::transaction(function () use ($appointment, $actor): CounsellingAppointment {
      $appointment->status = AppointmentStatus::Confirmed;
      $appointment->save();

      $case = $appointment->case;
      if ($case !== null && $case->status !== CaseStatus::Completed && $case->status !== CaseStatus::Cancelled) {
        $case->status = CaseStatus::Confirmed;
        $case->save();
      }

      if ($case !== null) {
        $this->auditService->record(
          $case,
          $actor,
          'appointment.confirmed',
          'Appointment confirmed',
          'Session #'.$appointment->session_number.' confirmed.',
          ['appointment_id' => $appointment->uuid],
        );
      }

      return $appointment->fresh(['counsellor.user', 'case.service']);
    });
  }

  public function markCompleted(CounsellingAppointment $appointment, ?User $actor = null): CounsellingAppointment
  {
    return DB::transaction(function () use ($appointment, $actor): CounsellingAppointment {
      $appointment->status = AppointmentStatus::Completed;
      $appointment->attended_at = now();
      $appointment->save();

      $case = $appointment->case;
      if ($case !== null) {
        $case->session_count = max($case->session_count, $appointment->session_number);
        $case->save();

        $this->auditService->record(
          $case,
          $actor,
          'appointment.completed',
          'Appointment completed',
          'Session #'.$appointment->session_number.' marked completed.',
          ['appointment_id' => $appointment->uuid],
        );
      }

      return $appointment->fresh(['counsellor.user', 'case.service']);
    });
  }

  public function markMissed(CounsellingAppointment $appointment, ?User $actor = null): CounsellingAppointment
  {
    return DB::transaction(function () use ($appointment, $actor): CounsellingAppointment {
      $appointment->status = AppointmentStatus::Missed;
      $appointment->save();

      $case = $appointment->case;
      if ($case !== null) {
        $this->auditService->record(
          $case,
          $actor,
          'appointment.missed',
          'Appointment missed',
          'Session #'.$appointment->session_number.' marked missed.',
          ['appointment_id' => $appointment->uuid],
        );
      }

      return $appointment->fresh(['counsellor.user', 'case.service']);
    });
  }
}
