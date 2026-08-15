<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncRegistrationFieldSettingsRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'settings' => ['required', 'array'],
      'settings.*.field_key' => ['required', 'string', 'max:80'],
      'settings.*.label' => ['nullable', 'string', 'max:255'],
      'settings.*.is_enabled' => ['boolean'],
      'settings.*.is_required' => ['boolean'],
      'settings.*.sort_order' => ['nullable', 'integer', 'min:0'],
      'settings.*.show_on_public' => ['boolean'],
      'settings.*.show_on_quick' => ['boolean'],
      'settings.*.help_text' => ['nullable', 'string', 'max:1000'],
      'settings.*.placeholder' => ['nullable', 'string', 'max:255'],
      'settings.*.field_type' => ['nullable', 'string', 'max:40'],
      'settings.*.options' => ['nullable', 'array'],
      'settings.*.metadata' => ['nullable', 'array'],
    ];
  }
}
