<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Region;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

final class ReportRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'event_id' => Event::class,
      'country_id' => CmsCountry::class,
      'region_id' => Region::class,
      'ministry_id' => CmsMinistry::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'country_id' => ['nullable', 'integer', 'exists:cms_countries,id'],
      'region_id' => ['nullable', 'integer', 'exists:regions,id'],
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'registration_status' => ['nullable', 'string', 'max:40'],
      'attendance_status' => ['nullable', 'string', 'max:40'],
      'report_type' => ['nullable', 'string', 'max:80'],
      'date_from' => ['nullable', 'date'],
      'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
    ];
  }
}
