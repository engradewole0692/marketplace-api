<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\VolunteerAssignmentStatus;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventVolunteerRole;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreVolunteerAssignmentRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'registration_id' => EventRegistration::class,
      'role_id' => EventVolunteerRole::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'registration_id' => ['required', 'integer', 'exists:event_registrations,id'],
      'role_id' => ['required', 'integer', 'exists:event_volunteer_roles,id'],
      'status' => ['nullable', Rule::enum(VolunteerAssignmentStatus::class)],
      'shift_starts_at' => ['nullable', 'date'],
      'shift_ends_at' => ['nullable', 'date'],
      'notes' => ['nullable', 'string'],
    ];
  }
}
