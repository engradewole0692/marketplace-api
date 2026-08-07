<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventRegistration */
final class EventRegistrationResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->whenLoaded('event', fn () => $this->event?->uuid, $this->event_id),
      'member_id' => $this->member?->uuid,
      'registrant' => [
        'name' => $this->contactName(),
        'email' => $this->contactEmail(),
        'phone' => $this->contactPhone(),
        'is_member' => $this->member_id !== null,
      ],
      'registration_number' => $this->registration_number,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'source' => $this->source,
      'emergency_contact_name' => $this->emergency_contact_name,
      'emergency_contact_relationship' => $this->emergency_contact_relationship,
      'emergency_contact_phone' => $this->emergency_contact_phone,
      'arrival_date' => $this->arrival_date?->toDateString(),
      'departure_date' => $this->departure_date?->toDateString(),
      'accommodation_required' => $this->accommodation_required,
      'airport_pickup_required' => $this->airport_pickup_required,
      'dietary_requirements' => $this->dietary_requirements,
      'medical_notes' => $this->medical_notes,
      'volunteer_interest' => $this->volunteer_interest,
      'prayer_requests' => $this->prayer_requests,
      'additional_notes' => $this->additional_notes,
      'consent_accepted' => $this->consent_accepted,
      'submitted_at' => $this->submitted_at?->toIso8601String(),
      'approved_at' => $this->approved_at?->toIso8601String(),
      'cancelled_at' => $this->cancelled_at?->toIso8601String(),
      'answers' => EventRegistrationQuestionAnswerResource::collection($this->whenLoaded('answers')),
      'check_in_token' => $this->whenLoaded('checkInToken', fn () => $this->checkInToken?->token),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
