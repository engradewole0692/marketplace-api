<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\MemberDocumentType;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMemberDocumentRequest extends FormRequest
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
      'document_type' => ['required', Rule::enum(MemberDocumentType::class)],
      'title' => ['required', 'string', 'max:180'],
      'file' => ['required', 'file', 'max:10240'],
    ];
  }
}
