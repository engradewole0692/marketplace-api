<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Region;
use App\Modules\Cms\Models\CmsCountry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class RegionSeeder extends Seeder
{
  public function run(): void
  {
    $defaults = [
      'nigeria' => ['Lagos', 'Abuja FCT', 'Rivers'],
      'south-africa' => ['Gauteng', 'Western Cape', 'KwaZulu-Natal'],
      'kenya' => ['Nairobi', 'Mombasa'],
      'ghana' => ['Greater Accra', 'Ashanti'],
      'usa' => ['Northeast', 'South', 'West'],
    ];

    foreach ($defaults as $countrySlug => $regionNames) {
      $country = CmsCountry::query()->where('slug', $countrySlug)->first();
      if ($country === null) {
        continue;
      }

      foreach ($regionNames as $index => $name) {
        Region::query()->updateOrCreate(
          [
            'country_id' => $country->id,
            'slug' => Str::slug($name),
          ],
          [
            'name' => $name,
            'is_active' => true,
            'sort_order' => $index + 1,
          ],
        );
      }
    }
  }
}
