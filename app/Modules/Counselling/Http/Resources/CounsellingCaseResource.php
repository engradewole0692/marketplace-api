<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Counselling\Models\CounsellingCase */
final class CounsellingCaseResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $latestPayment = $this->relationLoaded('latestPayment')
      ? $this->latestPayment
      : ($this->relationLoaded('payments') ? $this->payments->sortByDesc('created_at')->first() : null);

    $nextAppointment = $this->relationLoaded('nextAppointment')
      ? $this->nextAppointment
      : ($this->relationLoaded('appointments')
        ? $this->appointments
          ->filter(fn ($appointment) => in_array(
            $appointment->status instanceof \BackedEnum ? $appointment->status->value : (string) $appointment->status,
            ['scheduled', 'confirmed'],
            true,
          ) && $appointment->starts_at !== null && $appointment->starts_at->gte(now()))
          ->sortBy('starts_at')
          ->first()
        : null);

    return [
      'id' => $this->uuid,
      'case_number' => $this->case_number,
      'client_type' => $this->client_type instanceof \BackedEnum ? $this->client_type->value : $this->client_type,
      'status' => $this->status instanceof \BackedEnum
        ? (\App\Modules\Counselling\Enums\CaseStatus::tryFrom($this->status->value)?->normalize()->value
          ?? $this->status->value)
        : (\App\Modules\Counselling\Enums\CaseStatus::tryFrom((string) $this->status)?->normalize()->value
          ?? $this->status),
      'status_label' => $this->status instanceof \App\Modules\Counselling\Enums\CaseStatus
        ? $this->status->normalize()->label()
        : (\App\Modules\Counselling\Enums\CaseStatus::tryFrom((string) $this->status)?->normalize()->label()
          ?? str_replace('_', ' ', (string) $this->status)),
      'subject' => is_array($this->metadata) ? ($this->metadata['subject'] ?? null) : null,
      'preferred_language' => is_array($this->metadata) ? ($this->metadata['preferred_language'] ?? null) : null,
      'urgency' => is_array($this->metadata) ? ($this->metadata['urgency'] ?? null) : null,
      'preferred_format' => $this->preferred_format instanceof \BackedEnum
        ? $this->preferred_format->value
        : $this->preferred_format,
      'client_name' => $this->client_name,
      'client_email' => $this->client_email,
      'client_phone' => $this->client_phone,
      'client_country' => $this->client_country,
      'client_gender' => $this->client_gender,
      'preferred_counsellor_gender' => $this->preferred_counsellor_gender,
      'reason' => $this->reason,
      'prayer_request' => $this->prayer_request,
      'preferred_at' => $this->preferred_at?->toIso8601String(),
      'timezone' => $this->timezone,
      'session_count' => (int) $this->session_count,
      'allow_reschedule' => (bool) $this->allow_reschedule,
      'allow_cancel' => (bool) $this->allow_cancel,
      'assigned_at' => $this->assigned_at?->toIso8601String(),
      'scheduled_at' => $this->scheduled_at?->toIso8601String(),
      'completed_at' => $this->completed_at?->toIso8601String(),
      'cancelled_at' => $this->cancelled_at?->toIso8601String(),
      'cancellation_reason' => $this->cancellation_reason,
      'member_snapshot' => $this->member_snapshot,
      'metadata' => $this->metadata,
      'service' => $this->whenLoaded('service', fn () => $this->service
        ? CounsellingServiceResource::make($this->service)
        : null),
      'service_id' => $this->whenLoaded('service', fn () => $this->service?->uuid),
      'category_id' => $this->whenLoaded('category', fn () => $this->category?->uuid),
      'counsellor' => $this->whenLoaded('counsellor', fn () => $this->counsellor
        ? CounsellorResource::make($this->counsellor)
        : null),
      'counsellor_id' => $this->whenLoaded('counsellor', fn () => $this->counsellor?->uuid),
      'payment_status' => $latestPayment?->status instanceof \BackedEnum
        ? $latestPayment->status->value
        : ($latestPayment?->status ?? null),
      'payment' => $latestPayment ? [
        'id' => $latestPayment->uuid,
        'status' => $latestPayment->status instanceof \BackedEnum ? $latestPayment->status->value : $latestPayment->status,
        'amount' => (float) $latestPayment->amount,
        'currency' => $latestPayment->currency,
        'paid_at' => $latestPayment->paid_at?->toIso8601String(),
      ] : null,
      'next_appointment' => $nextAppointment ? [
        'id' => $nextAppointment->uuid,
        'status' => $nextAppointment->status instanceof \BackedEnum ? $nextAppointment->status->value : $nextAppointment->status,
        'starts_at' => $nextAppointment->starts_at?->toIso8601String(),
        'ends_at' => $nextAppointment->ends_at?->toIso8601String(),
        'format' => $nextAppointment->format instanceof \BackedEnum ? $nextAppointment->format->value : $nextAppointment->format,
        'meeting_link' => $nextAppointment->meeting_link,
        'location' => $nextAppointment->location,
      ] : null,
      'timeline' => $this->whenLoaded('events', fn () => $this->events
        ->sortBy('occurred_at')
        ->values()
        ->map(fn ($event) => [
          'id' => $event->uuid,
          'event_type' => $event->event_type,
          'title' => $event->title,
          'description' => $event->description,
          'occurred_at' => $event->occurred_at?->toIso8601String() ?? $event->created_at?->toIso8601String(),
          'actor_name' => $event->relationLoaded('actor') ? $event->actor?->name : null,
        ])
        ->all()),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
