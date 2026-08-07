<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

final class ApproveMemberRequest extends FormRequest
{
  public function authorize(): bool
  {
    $member = $this->route('member');

    return $member instanceof Member && ($this->user()?->can('approve', $member) ?? false);
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'reason' => ['nullable', 'string', 'max:500'],
    ];
  }
}
