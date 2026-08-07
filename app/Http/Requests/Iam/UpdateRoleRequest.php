<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoleRequest extends FormRequest
{
  public function authorize(): bool
  {
    $role = $this->route('role');

    return $role instanceof Role && ($this->user()?->can('update', $role) ?? false);
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    /** @var Role $role */
    $role = $this->route('role');

    return [
      'name' => ['sometimes', 'string', 'max:120'],
      'slug' => ['sometimes', 'string', 'max:120', Rule::unique('roles', 'slug')->ignore($role->id)],
      'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
      'guard_name' => ['sometimes', 'string', 'max:50'],
      'permission_ids' => ['sometimes', 'array'],
      'permission_ids.*' => ['integer', 'exists:permissions,id'],
    ];
  }
}
