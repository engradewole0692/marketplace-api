<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Member;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRegistrationRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'event_id' => Event::class,
      'member_id' => Member::class,
    ]);

    $eventId = $this->input('event_id');
    if ($eventId !== null && $eventId !== '' && ! is_numeric($eventId)) {
      $this->merge(['event_id' => null]);
    }
  }

  public function rules(): array
  {
    return [
      'event_id' => ['required', 'integer', 'exists:events,id'],
      'member_id' => ['nullable', 'integer', 'exists:members,id'],
      'registrant' => ['required_without:member_id', 'array'],
      'registrant.name' => ['required_without:member_id', 'string', 'max:255'],
      'registrant.email' => ['nullable', 'email', 'max:255'],
      'registrant.phone' => ['nullable', 'string', 'max:40'],
      'emergency_contact_name' => ['nullable', 'string', 'max:255'],
      'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
      'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
      'arrival_date' => ['nullable', 'date'],
      'departure_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
      'accommodation_required' => ['boolean'],
      'airport_pickup_required' => ['boolean'],
      'dietary_requirements' => ['nullable', 'string'],
      'medical_notes' => ['nullable', 'string'],
      'volunteer_interest' => ['boolean'],
      'prayer_requests' => ['nullable', 'string'],
      'additional_notes' => ['nullable', 'string'],
      'consent_accepted' => ['accepted'],
      'answers' => ['nullable', 'array'],
    ];
  }
}
