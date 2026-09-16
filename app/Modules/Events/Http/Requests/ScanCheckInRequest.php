<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ScanCheckInRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'token' => ['required', 'string', 'max:128'],
      'force' => ['nullable', 'boolean'],
      'notes' => ['nullable', 'string'],
      'event_session_id' => ['nullable', 'string'],
      'event_day_id' => ['nullable', 'string'],
      'event_id' => ['nullable', 'string'],
      'seating_area' => ['nullable', 'in:main_hall,overflow,none'],
      'capacity_override' => ['nullable', 'boolean'],
      'override_reason' => ['nullable', 'string', 'max:255'],
    ];
  }
}
