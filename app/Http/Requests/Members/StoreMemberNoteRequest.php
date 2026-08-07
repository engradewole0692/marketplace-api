<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMemberNoteRequest extends FormRequest
{
  public function authorize(): bool
  {
    $member = $this->route('member');

    return $member instanceof Member && ($this->user()?->can('update', $member) ?? false);
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'body' => ['required', 'string', 'min:2'],
      'is_private' => ['nullable', 'boolean'],
    ];
  }
}
