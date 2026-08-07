<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\VolunteerAssignmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVolunteerAssignmentRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'status' => ['nullable', Rule::enum(VolunteerAssignmentStatus::class)],
      'shift_starts_at' => ['nullable', 'date'],
      'shift_ends_at' => ['nullable', 'date'],
      'notes' => ['nullable', 'string'],
      'performance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
    ];
  }
}
