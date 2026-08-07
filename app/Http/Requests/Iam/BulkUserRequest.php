<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use App\Enums\BulkUserAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkUserRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()?->can('bulk', \App\Models\User::class) ?? false;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'action' => ['required', Rule::enum(BulkUserAction::class)],
      'user_ids' => ['required', 'array', 'min:1'],
      'user_ids.*' => ['integer'],
    ];
  }
}
