<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Models\Member;

final class UpdateMemberRequest extends StoreMemberRequest
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
    return $this->memberRules(requiredName: false);
  }
}
