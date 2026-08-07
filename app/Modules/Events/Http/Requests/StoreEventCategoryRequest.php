<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreEventCategoryRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, ['ministry_id' => CmsMinistry::class]);
  }

  public function rules(): array
  {
    return [
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'status' => ['nullable', 'string', 'max:40'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ];
  }
}
