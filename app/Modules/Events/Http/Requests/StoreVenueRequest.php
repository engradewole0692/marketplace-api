<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Models\Region;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Events\Support\UuidResolver;
use Illuminate\Foundation\Http\FormRequest;

class StoreVenueRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  protected function prepareForValidation(): void
  {
    UuidResolver::resolve($this, [
      'country_id' => CmsCountry::class,
      'region_id' => Region::class,
    ]);
  }

  public function rules(): array
  {
    return [
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'address_line_1' => ['nullable', 'string', 'max:255'],
      'address_line_2' => ['nullable', 'string', 'max:255'],
      'city' => ['nullable', 'string', 'max:255'],
      'country_id' => ['nullable', 'integer', 'exists:cms_countries,id'],
      'region_id' => ['nullable', 'integer', 'exists:regions,id'],
      'capacity' => ['nullable', 'integer', 'min:1'],
      'latitude' => ['nullable', 'numeric', 'between:-90,90'],
      'longitude' => ['nullable', 'numeric', 'between:-180,180'],
      'state' => ['nullable', 'string', 'max:120'],
      'postal_code' => ['nullable', 'string', 'max:40'],
      'timezone' => ['nullable', 'string', 'max:80'],
      'contact_name' => ['nullable', 'string', 'max:255'],
      'contact_email' => ['nullable', 'email', 'max:255'],
      'contact_phone' => ['nullable', 'string', 'max:40'],
      'status' => ['nullable', 'string', 'max:40'],
    ];
  }
}
