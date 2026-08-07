<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

class StoreSpeakerRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, ['photo_media_id' => CmsMedia::class]);
  }

  public function rules(): array
  {
    return [
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'title' => ['nullable', 'string', 'max:255'],
      'organization' => ['nullable', 'string', 'max:255'],
      'bio' => ['nullable', 'string'],
      'photo_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'email' => ['nullable', 'email', 'max:255'],
      'phone' => ['nullable', 'string', 'max:40'],
      'website_url' => ['nullable', 'url', 'max:255'],
      'status' => ['nullable', 'string', 'max:40'],
    ];
  }
}
