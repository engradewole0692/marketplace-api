<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\BulkMemberAction;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkMemberRequest extends FormRequest
{
  public function authorize(): bool
  {
    return $this->user()?->can('bulk', Member::class) ?? false;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'action' => ['required', Rule::enum(BulkMemberAction::class)],
      'member_ids' => ['required', 'array', 'min:1'],
      'member_ids.*' => ['integer'],
      'reason' => ['nullable', 'string', 'max:500'],
    ];
  }
}
