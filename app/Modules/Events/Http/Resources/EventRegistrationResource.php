<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistration */
final class EventRegistrationResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $latestPayment = $this->relationLoaded('payments')
      ? $this->payments->sortByDesc('id')->first()
      : EventRegistrationPayment::query()
        ->where('registration_id', $this->id)
        ->latest('id')
        ->first();

    $profile = is_array($this->metadata['profile'] ?? null) ? $this->metadata['profile'] : [];

    $latestCheckIn = $this->relationLoaded('checkIns')
      ? $this->checkIns->sortByDesc('checked_in_at')->first()
      : null;
    $latestCheckOut = $this->relationLoaded('attendanceHistories')
      ? $this->attendanceHistories->first(function ($history): bool {
        $status = $history->status instanceof \BackedEnum ? $history->status->value : (string) $history->status;

        return $status === 'checked_out';
      })
      : null;

    return [
      'id' => $this->uuid,
      'event_id' => $this->whenLoaded('event', fn () => $this->event?->uuid, $this->event_id),
      'event' => $this->whenLoaded('event', fn () => $this->event ? [
        'id' => $this->event->uuid,
        'title' => $this->event->title,
        'slug' => $this->event->slug,
        'starts_at' => $this->event->starts_at?->toIso8601String(),
        'ends_at' => $this->event->ends_at?->toIso8601String(),
      ] : null),
      'person_id' => $this->person?->uuid,
      'person_no' => $this->person?->person_no,
      'member_id' => $this->member?->uuid,
      'person' => $this->whenLoaded('person', fn () => $this->person ? [
        'id' => $this->person->uuid,
        'person_no' => $this->person->person_no,
        'name' => $this->person->fullName(),
        'email' => $this->person->email,
        'phone' => $this->person->phone,
        'country' => $this->person->country?->name,
        'region' => $this->person->region,
        'city' => $this->person->city,
        'is_member' => $this->member_id !== null,
      ] : null),
      'registrant' => [
        'name' => $this->contactName(),
        'email' => $this->contactEmail(),
        'phone' => $this->contactPhone(),
        'is_member' => $this->member_id !== null,
      ],
      'submitted' => [
        'name' => $this->submittedName(),
        'email' => $this->submittedEmail(),
        'phone' => $this->submittedPhone(),
      ],
      'registration_number' => $this->registration_number,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'payment_status' => $latestPayment?->status instanceof \BackedEnum
        ? $latestPayment->status->value
        : $latestPayment?->status,
      'source' => $this->source,
      'profile' => $profile,
      'planned_sessions' => EventSessionResource::collection($this->whenLoaded('plannedSessions')),
      'actual_sessions' => $this->whenLoaded('sessionAttendances', fn () => $this->sessionAttendances->map(fn ($row) => [
        'id' => $row->session?->uuid,
        'title' => $row->session?->title,
        'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
        'check_in_at' => $row->checked_in_at?->toIso8601String(),
        'check_out_at' => $row->checked_out_at?->toIso8601String(),
        'seating_area' => $row->seating_area instanceof \BackedEnum ? $row->seating_area->value : $row->seating_area,
        'counts_toward_seating' => (bool) $row->counts_toward_seating,
      ])->values()->all()),
      'emergency_contact_name' => $this->emergency_contact_name,
      'emergency_contact_relationship' => $this->emergency_contact_relationship,
      'emergency_contact_phone' => $this->emergency_contact_phone,
      'arrival_date' => $this->arrival_date?->toDateString(),
      'departure_date' => $this->departure_date?->toDateString(),
      'accommodation_required' => $this->accommodation_required,
      'airport_pickup_required' => $this->airport_pickup_required,
      'seat_reservation' => $this->seat_reservation,
      'dietary_requirements' => $this->dietary_requirements,
      'medical_notes' => $this->medical_notes,
      'volunteer_interest' => $this->volunteer_interest,
      'prayer_requests' => $this->prayer_requests,
      'additional_notes' => $this->additional_notes,
      'consent_accepted' => $this->consent_accepted,
      'submitted_at' => $this->submitted_at?->toIso8601String(),
      'approved_at' => $this->approved_at?->toIso8601String(),
      'cancelled_at' => $this->cancelled_at?->toIso8601String(),
      'checked_in_at' => $latestCheckIn?->checked_in_at?->toIso8601String(),
      'checked_out_at' => $latestCheckOut?->occurred_at?->toIso8601String(),
      'answers' => EventRegistrationQuestionAnswerResource::collection($this->whenLoaded('answers')),
      'services' => EventRegServiceResource::collection($this->whenLoaded('services')),
      'payments' => EventRegistrationPaymentResource::collection($this->whenLoaded('payments')),
      'timeline' => EventRegistrationTimelineResource::collection($this->whenLoaded('timelines')),
      'audit_logs' => EventRegistrationAuditLogResource::collection($this->whenLoaded('auditLogs')),
      'status_transitions' => EventRegistrationStatusTransitionResource::collection($this->whenLoaded('statusTransitions')),
      'check_in_token' => $this->whenLoaded('checkInToken', fn () => $this->checkInToken?->token),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
