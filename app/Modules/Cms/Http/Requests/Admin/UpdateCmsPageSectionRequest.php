<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCmsPageSectionRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  /**
   * @return array<string, mixed>
   */
  public function rules(): array
  {
    return [
      'title' => ['nullable', 'string', 'max:255'],
      'content' => ['sometimes', 'array'],
      'draft_content' => ['sometimes', 'array'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'status' => ['sometimes', 'string', 'in:draft,review,published'],
    ];
  }
}
