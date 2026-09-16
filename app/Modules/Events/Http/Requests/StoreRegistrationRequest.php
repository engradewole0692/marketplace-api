<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Member;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\RegistrationFormConfigService;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Contracts\Validation\Validator;
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

    $registrant = is_array($this->input('registrant')) ? $this->input('registrant') : [];
    $profile = is_array($this->input('profile')) ? $this->input('profile') : [];

    if (empty($registrant['name'])) {
      $first = trim((string) ($registrant['first_name'] ?? $profile['first_name'] ?? $this->input('first_name') ?? ''));
      $last = trim((string) ($registrant['last_name'] ?? $profile['last_name'] ?? $this->input('last_name') ?? ''));
      $combined = trim($first.' '.$last);
      if ($combined !== '') {
        $registrant['name'] = $combined;
      }
    }

    foreach (RegistrationFormConfigService::METADATA_PROFILE_FIELDS as $key) {
      if (! array_key_exists($key, $profile) && $this->filled($key)) {
        $profile[$key] = $this->input($key);
      }
    }

    $this->merge([
      'registrant' => $registrant === [] ? $this->input('registrant') : $registrant,
      'profile' => $profile === [] ? $this->input('profile') : $profile,
    ]);
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
      'registrant.first_name' => ['nullable', 'string', 'max:120'],
      'registrant.last_name' => ['nullable', 'string', 'max:120'],
      'profile' => ['nullable', 'array'],
      'profile.*' => ['nullable'],
      'emergency_contact_name' => ['nullable', 'string', 'max:255'],
      'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
      'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
      'arrival_date' => ['nullable', 'date'],
      'departure_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
      'accommodation_required' => ['boolean'],
      'airport_pickup_required' => ['boolean'],
      'transport_required' => ['boolean'],
      'travel_assistance' => ['boolean'],
      'accommodation' => ['nullable', 'array'],
      'accommodation.option_id' => ['nullable', 'string'],
      'accommodation.occupancy_type' => ['nullable', 'in:private,shared'],
      'accommodation.arrival_date' => ['nullable', 'date'],
      'accommodation.departure_date' => ['nullable', 'date', 'after:accommodation.arrival_date'],
      'accommodation.share_with' => ['nullable', 'array'],
      'accommodation.share_with.*' => ['string'],
      'accommodation.notes' => ['nullable', 'string'],
      'transport_trips' => ['nullable', 'array'],
      'transport_trips.*.option_id' => ['nullable', 'string'],
      'transport_trips.*.route' => ['nullable', 'string', 'max:160'],
      'transport_trips.*.pickup_location' => ['nullable', 'string', 'max:255'],
      'transport_trips.*.dropoff_location' => ['nullable', 'string', 'max:255'],
      'transport_trips.*.trip_date' => ['nullable', 'date'],
      'transport_trips.*.trip_time' => ['nullable', 'string', 'max:20'],
      'transport_trips.*.passengers' => ['nullable', 'integer', 'min:1'],
      'transport_trips.*.luggage' => ['nullable', 'string', 'max:120'],
      'transport_trips.*.special_requirements' => ['nullable', 'string'],
      'transport_trips.*.flight_info' => ['nullable', 'array'],
      'travel' => ['nullable', 'array'],
      'travel.trip_type' => ['nullable', 'in:one_way,return,multi_city'],
      'travel.origin' => ['nullable', 'string', 'max:160'],
      'travel.destination' => ['nullable', 'string', 'max:160'],
      'travel.departure_date' => ['nullable', 'date'],
      'travel.return_date' => ['nullable', 'date'],
      'travel.airline_preference' => ['nullable', 'string', 'max:120'],
      'travel.travel_class' => ['nullable', 'in:economy,premium_economy,business,first,other'],
      'travel.passengers' => ['nullable', 'integer', 'min:1'],
      'travel.passenger_names' => ['nullable', 'array'],
      'travel.preferred_departure_time' => ['nullable', 'string', 'max:40'],
      'travel.preferred_return_time' => ['nullable', 'string', 'max:40'],
      'travel.notes' => ['nullable', 'string'],
      'seat_reservation' => ['nullable', 'string', 'max:120'],
      'dietary_requirements' => ['nullable', 'string'],
      'medical_notes' => ['nullable', 'string'],
      'volunteer_interest' => ['boolean'],
      'prayer_requests' => ['nullable', 'string'],
      'additional_notes' => ['nullable', 'string'],
      'occupation' => ['nullable', 'string', 'max:255'],
      'organization' => ['nullable', 'string', 'max:255'],
      'country' => ['nullable', 'string', 'max:120'],
      'state_region' => ['nullable', 'string', 'max:120'],
      'city' => ['nullable', 'string', 'max:120'],
      'gender' => ['nullable', 'string', 'max:80'],
      'address' => ['nullable', 'string', 'max:500'],
      'ministry' => ['nullable', 'string', 'max:255'],
      'membership_status' => ['nullable', 'string', 'max:120'],
      'accommodation_type' => ['nullable', 'string', 'max:120'],
      'special_requirements' => ['nullable', 'string'],
      'date_of_birth' => ['nullable', 'date'],
      'consent_accepted' => ['accepted'],
      'answers' => ['nullable', 'array'],
      'planned_session_ids' => ['nullable', 'array'],
      'planned_session_ids.*' => ['string'],
      'phone_country_code' => ['nullable', 'string', 'max:8'],
      'registrant.phone_country_code' => ['nullable', 'string', 'max:8'],
      'accommodation.occupants' => ['nullable', 'array'],
      'accommodation.occupants.*.name' => ['nullable', 'string', 'max:255'],
      'accommodation.occupants.*.gender' => ['nullable', 'in:male,female,Male,Female'],
      'accommodation.occupant_count' => ['nullable', 'integer', 'min:1'],
    ];
  }

  public function withValidator(Validator $validator): void
  {
    $validator->after(function (Validator $validator): void {
      $eventId = $this->input('event_id');
      if (! is_numeric($eventId)) {
        return;
      }

      $event = Event::query()->find((int) $eventId);
      if ($event === null) {
        return;
      }

      app(RegistrationFormConfigService::class)->validateSubmission(
        $event,
        $this->all(),
        $validator,
        RegistrationFormConfigService::CONTEXT_PUBLIC,
      );
    });
  }
}
