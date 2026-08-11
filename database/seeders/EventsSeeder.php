<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventCategory;
use App\Modules\Events\Models\Venue;
use Illuminate\Database\Seeder;

final class EventsSeeder extends Seeder
{
  public function run(): void
  {
    $ministry = CmsMinistry::query()->first();
    $country = CmsCountry::query()->first();

    $category = EventCategory::query()->updateOrCreate(
      ['slug' => 'conferences'],
      [
        'ministry_id' => $ministry?->id,
        'name' => 'Conferences',
        'description' => 'Large gatherings, summits, and conferences.',
        'status' => 'active',
        'sort_order' => 1,
      ],
    );

    $venue = Venue::query()->updateOrCreate(
      ['slug' => 'main-auditorium'],
      [
        'name' => 'Main Auditorium',
        'description' => 'Primary event auditorium.',
        'city' => $country?->name,
        'country_id' => $country?->id,
        'capacity' => 500,
        'status' => 'active',
      ],
    );

    Event::query()->updateOrCreate(
      ['slug' => 'kingdom-leadership-summit'],
      [
        'ministry_id' => $ministry?->id,
        'event_category_id' => $category->id,
        'venue_id' => $venue->id,
        'country_id' => $country?->id,
        'title' => 'Kingdom Leadership Summit',
        'theme' => 'Raising Marketplace Leaders',
        'summary' => 'An annual gathering for marketplace ministry leaders.',
        'description' => 'Join us for teaching, networking, and commissioning of marketplace leaders across the network.',
        'starts_at' => now()->addMonths(2),
        'ends_at' => now()->addMonths(2)->addDays(2),
        'timezone' => 'UTC',
        'registration_opens_at' => now(),
        'registration_deadline' => now()->addMonths(2)->subDays(3),
        'capacity' => 300,
        'check_in_enabled' => true,
        'certificate_enabled' => true,
        'visibility' => EventVisibility::Public,
        // Public `/events` only lists Published events — seed as Published so
        // a fresh production deploy is not an empty calendar until an admin publishes.
        'status' => EventStatus::Published,
      ],
    );
  }
}
