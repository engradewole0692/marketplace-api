<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class CounsellingSeeder extends Seeder
{
  public function run(): void
  {
    $categories = [
      ['name' => 'Marriage', 'icon' => 'heart'],
      ['name' => 'Relationship', 'icon' => 'users'],
      ['name' => 'Family', 'icon' => 'home'],
      ['name' => 'Leadership', 'icon' => 'crown'],
      ['name' => 'Business', 'icon' => 'briefcase'],
      ['name' => 'Career', 'icon' => 'graduation-cap'],
      ['name' => 'Finance', 'icon' => 'wallet'],
      ['name' => 'Emotional Health', 'icon' => 'heart-handshake'],
      ['name' => 'Addiction Recovery', 'icon' => 'shield'],
      ['name' => 'Trauma Recovery', 'icon' => 'sparkles'],
      ['name' => 'Youth', 'icon' => 'users'],
      ['name' => 'Singles', 'icon' => 'user'],
      ['name' => 'Ministers', 'icon' => 'book-open'],
      ['name' => 'Parenting', 'icon' => 'baby'],
      ['name' => 'Spiritual Growth', 'icon' => 'flame'],
    ];

    $categoryModels = [];
    foreach ($categories as $index => $row) {
      $slug = Str::slug($row['name']);
      $categoryModels[$slug] = CounsellingCategory::query()->updateOrCreate(
        ['slug' => $slug],
        [
          'name' => $row['name'],
          'description' => $row['name'].' counselling for marketplace leaders and members.',
          'icon' => $row['icon'],
          'sort_order' => $index,
          'is_visible' => true,
          'status' => 'active',
          'seo_title' => $row['name'].' Counselling',
          'seo_description' => 'Confidential '.$row['name'].' counselling with Marketplace Ministers.',
        ],
      );
    }

    $services = [
      [
        'title' => 'Marriage & Relationship Counselling',
        'slug' => 'marriage-relationship-counselling',
        'category' => 'marriage',
        'format' => 'hybrid',
        'is_free' => true,
        'requires_payment' => false,
        'featured' => true,
        'short' => 'Biblical counsel for marriages and relationships under marketplace pressure.',
      ],
      [
        'title' => 'Leadership & Ministry Mentoring',
        'slug' => 'leadership-ministry-mentoring',
        'category' => 'leadership',
        'format' => 'virtual',
        'is_free' => false,
        'requires_payment' => true,
        'visitor_price' => 75,
        'member_price' => 45,
        'featured' => true,
        'short' => 'One-to-one sessions for leaders navigating calling, conflict, and growth.',
      ],
      [
        'title' => 'Business & Career Guidance',
        'slug' => 'business-career-guidance',
        'category' => 'business',
        'format' => 'virtual',
        'is_free' => false,
        'requires_payment' => true,
        'visitor_price' => 60,
        'member_price' => 35,
        'featured' => false,
        'short' => 'Faith-centered guidance for career transitions and marketplace strategy.',
      ],
      [
        'title' => 'Emotional & Spiritual Health',
        'slug' => 'emotional-spiritual-health',
        'category' => 'emotional-health',
        'format' => 'hybrid',
        'is_free' => true,
        'requires_payment' => false,
        'featured' => true,
        'short' => 'Confidential support for emotional wellness and spiritual growth.',
      ],
      [
        'title' => 'Family & Parenting Support',
        'slug' => 'family-parenting-support',
        'category' => 'family',
        'format' => 'physical',
        'is_free' => true,
        'requires_payment' => false,
        'featured' => false,
        'short' => 'In-person and hybrid support for families and parenting seasons.',
      ],
    ];

    foreach ($services as $index => $service) {
      $category = $categoryModels[$service['category']] ?? null;

      CounsellingService::query()->updateOrCreate(
        ['slug' => $service['slug']],
        [
          'category_id' => $category?->id,
          'title' => $service['title'],
          'description' => $service['short'].' Sessions are confidential and led by trained counsellors.',
          'short_description' => $service['short'],
          'icon' => $category?->icon,
          'duration_minutes' => 60,
          'format' => $service['format'],
          'maximum_sessions' => 3,
          'requires_approval' => true,
          'requires_payment' => (bool) $service['requires_payment'],
          'is_free' => (bool) $service['is_free'],
          'visitor_price' => $service['visitor_price'] ?? null,
          'member_price' => $service['member_price'] ?? null,
          'currency' => 'USD',
          'is_visible' => true,
          'is_featured' => (bool) $service['featured'],
          'sort_order' => $index,
          'status' => 'published',
          'seo_title' => $service['title'],
          'seo_description' => $service['short'],
        ],
      );
    }
  }
}
