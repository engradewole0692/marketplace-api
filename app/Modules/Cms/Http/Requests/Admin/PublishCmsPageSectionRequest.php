<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class PublishCmsPageSectionRequest extends FormRequest
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
      'change_summary' => ['nullable', 'string', 'max:500'],
      'draft_content' => ['sometimes', 'array'],
    ];
  }
}
