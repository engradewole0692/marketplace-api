<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Region;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\EventCategory;
use App\Modules\Events\Models\Venue;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'ministry_id' => CmsMinistry::class,
      'event_category_id' => EventCategory::class,
      'venue_id' => Venue::class,
      'country_id' => CmsCountry::class,
      'region_id' => Region::class,
      'banner_media_id' => CmsMedia::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'ministry_id' => ['nullable', 'integer', 'exists:cms_ministries,id'],
      'event_category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
      'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
      'country_id' => ['nullable', 'integer', 'exists:cms_countries,id'],
      'region_id' => ['nullable', 'integer', 'exists:regions,id'],
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'theme' => ['nullable', 'string', 'max:255'],
      'theme_scripture' => ['nullable', 'string', 'max:255'],
      'theme_color' => ['nullable', 'string', 'max:30'],
      'banner_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'summary' => ['nullable', 'string'],
      'description' => ['nullable', 'string'],
      'starts_at' => ['nullable', 'date'],
      'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
      'timezone' => ['nullable', 'string', 'max:64'],
      'registration_opens_at' => ['nullable', 'date'],
      'registration_deadline' => ['nullable', 'date'],
      'capacity' => ['nullable', 'integer', 'min:1'],
      'check_in_enabled' => ['boolean'],
      'certificate_enabled' => ['boolean'],
      'attendance_required' => ['boolean'],
      'visibility' => ['nullable', Rule::enum(EventVisibility::class)],
      'status' => ['nullable', Rule::enum(EventStatus::class)],
      'is_featured' => ['boolean'],
      'is_paid' => ['boolean'],
      'payment_required' => ['boolean'],
      'price' => ['nullable', 'numeric', 'min:0'],
      'currency' => ['nullable', 'string', 'size:3'],
      'seo_title' => ['nullable', 'string', 'max:255'],
      'seo_description' => ['nullable', 'string'],
      'announcement' => ['nullable', 'string'],
      'certificate_template_id' => ['nullable', 'integer', 'exists:event_certificate_templates,id'],
      'sessions' => ['nullable', 'array'],
      'sessions.*.title' => ['required_with:sessions', 'string', 'max:255'],
      'sessions.*.starts_at' => ['nullable', 'date'],
      'sessions.*.ends_at' => ['nullable', 'date'],
      'sessions.*.track' => ['nullable', 'string', 'max:255'],
      'sessions.*.room' => ['nullable', 'string', 'max:255'],
      'sessions.*.location' => ['nullable', 'string', 'max:255'],
      'sessions.*.capacity' => ['nullable', 'integer', 'min:1'],
    ];
  }
}
