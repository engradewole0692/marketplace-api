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
      'consent_accepted' => ['boolean'],
      'check_in_immediately' => ['boolean'],
      'answers' => ['nullable', 'array'],
      'accommodation_required' => ['boolean'],
      'seat_reservation' => ['nullable', 'string', 'max:120'],
      'dietary_requirements' => ['nullable', 'string'],
      'additional_notes' => ['nullable', 'string'],
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

      app(RegistrationFormConfigService::class)->validateSubmission($event, $this->all(), $validator);
    });
  }
}
