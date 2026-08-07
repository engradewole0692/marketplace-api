<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'event_id' => Event::class,
      'filters.ministry_id' => CmsMinistry::class,
      'filters.country_id' => CmsCountry::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'export_type' => ['required', Rule::in([
        'registrations', 'attendance', 'certificates', 'volunteers', 'speakers', 'sessions', 'revenue',
      ])],
      'format' => ['required', Rule::in(['csv', 'xlsx'])],
      'filters' => ['nullable', 'array'],
      'filters.ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'filters.country_id' => ['nullable', 'integer', 'exists:cms_countries,id'],
      'filters.registration_status' => ['nullable', 'string', 'max:40'],
      'filters.attendance_status' => ['nullable', 'string', 'max:40'],
    ];
  }
}
