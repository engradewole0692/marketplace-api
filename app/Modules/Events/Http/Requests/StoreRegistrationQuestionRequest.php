<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRegistrationQuestionRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'field_key' => ['nullable', 'string', 'max:80'],
      'question' => ['required', 'string', 'max:500'],
      'help_text' => ['nullable', 'string', 'max:1000'],
      'answer_type' => ['nullable', 'string', Rule::in(['text', 'textarea', 'number', 'email', 'phone', 'select', 'radio', 'checkbox', 'date', 'yes_no'])],
      'options' => ['nullable', 'array'],
      'options.*' => ['nullable'],
      'is_enabled' => ['boolean'],
      'is_required' => ['boolean'],
      'show_on_public' => ['boolean'],
      'show_on_quick' => ['boolean'],
      'maps_to_member_field' => ['nullable', 'string', 'max:80'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'metadata' => ['nullable', 'array'],
    ];
  }
}
