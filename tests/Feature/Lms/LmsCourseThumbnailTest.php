<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\CourseThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LmsCourseThumbnailTest extends TestCase
{
  use RefreshDatabase;

  public function test_youtube_trailer_resolves_thumbnail_url_in_public_api(): void
  {
    $course = Course::query()->create([
      'title' => 'Trailer Thumbnail Course',
      'slug' => 'trailer-thumbnail',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'trailer_youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);

    $response = $this->getJson('/api/v1/public/courses/trailer-thumbnail');

    $response->assertOk()
      ->assertJsonPath('data.course.thumbnail_url', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
  }

  public function test_import_metadata_thumbnail_is_used_when_no_custom_upload(): void
  {
    Course::query()->create([
      'title' => 'Import Thumb Course',
      'slug' => 'import-thumb',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'metadata' => ['import' => ['thumbnail_url' => 'https://cdn.example.com/thumb.jpg']],
    ]);

    $this->getJson('/api/v1/public/courses/import-thumb')
      ->assertOk()
      ->assertJsonPath('data.course.thumbnail_url', 'https://cdn.example.com/thumb.jpg');
  }

  public function test_thumbnail_service_prefers_custom_over_youtube(): void
  {
    $course = Course::query()->create([
      'title' => 'Custom Wins',
      'slug' => 'custom-wins',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'trailer_youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'metadata' => ['import' => ['thumbnail_url' => 'https://cdn.example.com/custom.jpg']],
    ]);

    $url = app(CourseThumbnailService::class)->resolve($course->fresh());

    $this->assertSame('https://cdn.example.com/custom.jpg', $url);
  }
}
