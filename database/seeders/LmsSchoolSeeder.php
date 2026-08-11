<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\LmsSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class LmsSchoolSeeder extends Seeder
{
  /** @var list<array{title: string, slug: string, summary: string, member_price: float, public_price: float}> */
  private array $schools = [
    [
      'title' => 'School of Teachers',
      'slug' => 'school-of-teachers',
      'summary' => 'Formation for teachers called to instruct with clarity, character, and kingdom conviction.',
      'member_price' => 150.0,
      'public_price' => 200.0,
    ],
    [
      'title' => 'School of Pastors',
      'slug' => 'school-of-pastors',
      'summary' => 'Pastoral leadership development for shepherds stewarding congregations and communities.',
      'member_price' => 200.0,
      'public_price' => 275.0,
    ],
    [
      'title' => 'School of Evangelists',
      'slug' => 'school-of-evangelists',
      'summary' => 'Equipping evangelists to proclaim the gospel with power, compassion, and cultural relevance.',
      'member_price' => 150.0,
      'public_price' => 200.0,
    ],
    [
      'title' => 'School of Prophets',
      'slug' => 'school-of-prophets',
      'summary' => 'Training for prophetic ministry grounded in scripture, discernment, and accountable community.',
      'member_price' => 175.0,
      'public_price' => 225.0,
    ],
    [
      'title' => 'School of Apostles',
      'slug' => 'school-of-apostles',
      'summary' => 'Apostolic formation for pioneers building movements, teams, and sustainable kingdom works.',
      'member_price' => 225.0,
      'public_price' => 300.0,
    ],
  ];

  public function run(): void
  {
    foreach ($this->schools as $index => $school) {
      LmsSchool::query()->updateOrCreate(
        ['slug' => $school['slug']],
        [
          'uuid' => LmsSchool::query()->where('slug', $school['slug'])->value('uuid') ?? (string) Str::uuid(),
          'title' => $school['title'],
          'subtitle' => 'Marketplace Ministers Learning Programme',
          'summary' => $school['summary'],
          'description' => $school['summary'],
          'status' => SchoolStatus::Draft,
          'sort_order' => $index + 1,
          'member_price' => $school['member_price'],
          'public_price' => $school['public_price'],
          'currency' => 'USD',
          'certificate_enabled' => true,
          'sequential_progression' => true,
        ],
      );
    }
  }
}
