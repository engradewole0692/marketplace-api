<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()?->can('create', \App\Models\Role::class) ?? false;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'name' => ['required', 'string', 'max:120'],
      'slug' => ['nullable', 'string', 'max:120', 'unique:roles,slug'],
      'description' => ['nullable', 'string', 'max:1000'],
      'guard_name' => ['nullable', 'string', 'max:50'],
      'permission_ids' => ['nullable', 'array'],
      'permission_ids.*' => ['integer', 'exists:permissions,id'],
    ];
  }
}
