<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionMemberStatusRequest extends FormRequest
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
      'status' => ['required', Rule::enum(MemberStatus::class)],
      'reason' => ['nullable', 'string', 'max:500'],
    ];
  }
}
