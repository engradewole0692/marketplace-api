<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends FormRequest
{
  public function authorize(): bool
  {
    $user = $this->route('user');

    return $user instanceof User && ($this->user()?->can('update', $user) ?? false);
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    /** @var User $user */
    $user = $this->route('user');

    return [
      'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
      'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
      'display_name' => ['sometimes', 'nullable', 'string', 'max:180'],
      'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
      'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
      'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
      'must_change_password' => ['sometimes', 'boolean'],
      'status' => ['sometimes', Rule::enum(UserStatus::class)],
      'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
      'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
      'role_ids' => ['sometimes', 'array'],
      'role_ids.*' => ['integer', 'exists:roles,id'],
    ];
  }
}
