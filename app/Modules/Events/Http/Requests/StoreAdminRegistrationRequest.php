<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Member;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\RegistrationFormConfigService;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAdminRegistrationRequest extends FormRequest
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

    $memberId = $this->input('member_id');
    if ($memberId !== null && $memberId !== '' && ! is_numeric($memberId)) {
      $this->merge(['member_id' => null]);
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

    foreach (['first_name', 'last_name', 'occupation', 'organization', 'country', 'state_region', 'city', 'gender'] as $key) {
      if (! array_key_exists($key, $profile) && $this->filled($key)) {
        $profile[$key] = $this->input($key);
      }
      if (! array_key_exists($key, $registrant) && isset($profile[$key])) {
        $registrant[$key] = $profile[$key];
      }
    }

    // Resolve existing identity before validation so required contact fields
    // are not forced when a member can be matched by email/phone.
    if (empty($this->input('member_id')) && $registrant !== []) {
      $resolved = app(\App\Modules\Events\Support\EventRegistrantResolver::class)->resolve($registrant);
      if ($resolved['member'] !== null) {
        $this->merge(['member_id' => $resolved['member']->id]);
      }
    }

    $payload = [];
    if ($registrant !== []) {
      $payload['registrant'] = $registrant;
    }
    if ($profile !== []) {
      $payload['profile'] = $profile;
    }
    if ($payload !== []) {
      $this->merge($payload);
    }
  }

  public function rules(): array
  {
    return [
      'event_id' => ['required', 'integer', 'exists:events,id'],
      'member_id' => ['nullable', 'integer', 'exists:members,id'],
      'registrant' => ['required_without:member_id', 'nullable', 'array'],
      'registrant.name' => ['required_without:member_id', 'nullable', 'string', 'max:255'],
      'registrant.email' => ['nullable', 'email', 'max:255'],
      'registrant.phone' => ['nullable', 'string', 'max:40'],
      'registrant.first_name' => ['nullable', 'string', 'max:120'],
      'registrant.last_name' => ['nullable', 'string', 'max:120'],
      'consent_accepted' => ['boolean'],
      'check_in_immediately' => ['boolean'],
      'answers' => ['nullable', 'array'],
      'profile' => ['nullable', 'array'],
      'profile.*' => ['nullable'],
      'accommodation_required' => ['boolean'],
      'airport_pickup_required' => ['boolean'],
      'volunteer_interest' => ['boolean'],
      'seat_reservation' => ['nullable', 'string', 'max:120'],
      'dietary_requirements' => ['nullable', 'string'],
      'additional_notes' => ['nullable', 'string'],
      'medical_notes' => ['nullable', 'string'],
      'prayer_requests' => ['nullable', 'string'],
      'emergency_contact_name' => ['nullable', 'string', 'max:255'],
      'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
      'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
      'arrival_date' => ['nullable', 'date'],
      'departure_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
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
        RegistrationFormConfigService::CONTEXT_QUICK,
      );
    });
  }
}
