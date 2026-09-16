<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CheckInRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, ['event_session_id' => EventSession::class]);
  }

  public function rules(): array
  {
    return [
      'event_session_id' => ['nullable', 'integer', 'exists:event_sessions,id'],
      'event_day_id' => ['nullable', 'string'],
      'method' => ['nullable', Rule::enum(CheckInMethod::class)],
      'checked_in_at' => ['nullable', 'date'],
      'checked_out_at' => ['nullable', 'date'],
      'notes' => ['nullable', 'string'],
      'seating_area' => ['nullable', 'in:main_hall,overflow,none'],
      'capacity_override' => ['nullable', 'boolean'],
      'override_reason' => ['nullable', 'string', 'max:255'],
    ];
  }
}
