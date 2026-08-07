<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ReorderCmsPageSectionsRequest extends FormRequest
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
      'sections' => ['required', 'array', 'min:1'],
      'sections.*.id' => ['required', 'uuid'],
      'sections.*.sort_order' => ['required', 'integer', 'min:0'],
    ];
  }
}
