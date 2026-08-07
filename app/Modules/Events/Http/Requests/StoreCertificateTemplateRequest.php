<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCertificateTemplateRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'event_id' => Event::class,
      'background_media_id' => CmsMedia::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'html_body' => ['required', 'string'],
      'background_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'is_active' => ['boolean'],
      'sort_order' => ['nullable', 'integer'],
    ];
  }
}
