<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Reference catalogue for LMS admin (categories + levels). Courses remain admin-created.
 */
final class LmsReferenceSeeder extends Seeder
{
  public function run(): void
  {
    $categories = [
      'Marketplace Discipleship',
      'Leadership',
      'Prayer & Intercession',
      'Business & Calling',
      'Family & Relationships',
    ];

    foreach ($categories as $index => $name) {
      CourseCategory::query()->updateOrCreate(
        ['slug' => Str::slug($name)],
        [
          'name' => $name,
          'description' => $name.' courses for marketplace ministers.',
          'sort_order' => $index + 1,
          'status' => CatalogStatus::Active,
          'is_visible' => true,
        ],
      );
    }

    $levels = [
      'Foundation',
      'Intermediate',
      'Advanced',
      'Leadership Intensive',
    ];

    foreach ($levels as $index => $name) {
      CourseLevel::query()->updateOrCreate(
        ['slug' => Str::slug($name)],
        [
          'name' => $name,
          'description' => $name.' learning track.',
          'sort_order' => $index + 1,
          'status' => CatalogStatus::Active,
        ],
      );
    }
  }
}
