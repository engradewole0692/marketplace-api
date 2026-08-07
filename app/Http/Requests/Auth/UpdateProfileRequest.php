<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user() !== null;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'first_name' => ['sometimes', 'string', 'max:100'],
      'last_name' => ['sometimes', 'string', 'max:100'],
      'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
      'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
      'timezone' => ['sometimes', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
      'locale' => ['sometimes', 'string', 'max:10'],
    ];
  }
}
