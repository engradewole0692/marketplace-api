<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()?->can('create', \App\Models\User::class) ?? false;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'first_name' => ['nullable', 'string', 'max:120'],
      'last_name' => ['nullable', 'string', 'max:120'],
      'display_name' => ['nullable', 'string', 'max:180'],
      'email' => ['required', 'email', 'max:255', 'unique:users,email'],
      'phone' => ['nullable', 'string', 'max:40'],
      'password' => ['required', 'string', Password::defaults()],
      'status' => ['nullable', Rule::enum(UserStatus::class)],
      'timezone' => ['nullable', 'string', 'max:64'],
      'locale' => ['nullable', 'string', 'max:12'],
      'role_ids' => ['nullable', 'array'],
      'role_ids.*' => ['integer', 'exists:roles,id'],
    ];
  }
}
