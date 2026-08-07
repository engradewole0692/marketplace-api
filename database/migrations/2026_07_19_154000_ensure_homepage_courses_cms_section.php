<?php

declare(strict_types=1);

use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensure homepage has a CMS-managed courses section (existing cms_page_sections).
 */
return new class extends Migration
{
  public function up(): void
  {
    $page = CmsPage::query()->where('slug', 'home')->first();
    if ($page === null) {
      return;
    }

    $content = [
      'eyebrow' => 'Learning',
      'title' => 'Featured Courses',
      'subtitle' => 'Grow in faith and marketplace leadership through our learning programmes.',
      'description' => 'CMS-managed course collections for the public homepage.',
      'banner_image_asset' => '',
      'grids' => [
        [
          'id' => 'featured',
          'title' => 'Featured Courses',
          'subtitle' => 'Hand-picked learning paths for the tribe.',
          'source' => 'featured',
          'limit' => 3,
          'visible' => true,
          'course_slugs' => [],
        ],
        [
          'id' => 'popular',
          'title' => 'Popular Courses',
          'subtitle' => 'Courses learners engage with most.',
          'source' => 'popular',
          'limit' => 3,
          'visible' => true,
          'course_slugs' => [],
        ],
        [
          'id' => 'latest',
          'title' => 'Latest Courses',
          'subtitle' => 'Recently published learning paths.',
          'source' => 'latest',
          'limit' => 3,
          'visible' => true,
          'course_slugs' => [],
        ],
      ],
      'cta_title' => 'Start learning today',
      'cta_subtitle' => 'Browse the catalogue or create a Learning Portal account to enrol as a public learner.',
      'cta_primary' => 'Browse courses',
      'cta_primary_to' => '/courses',
      'cta_secondary' => 'Create learner account',
      'cta_secondary_to' => '/learn/register',
      'items' => [],
    ];

    CmsPageSection::query()->updateOrCreate(
      [
        'page_slug' => 'home',
        'section_key' => 'courses',
      ],
      [
        'page_id' => $page->id,
        'section_type' => 'courses',
        'title' => 'Courses',
        'content' => $content,
        'draft_content' => $content,
        'is_active' => true,
        'status' => 'published',
        'sort_order' => 10,
        'published_at' => now(),
      ],
    );
  }

  public function down(): void
  {
    // Keep content — non-destructive.
  }
};
