<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\YoutubeMetadataService;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Iam\IamTestCase;

final class LmsCourseOperationsTest extends IamTestCase
{
  public function test_course_ops_create_publish_schedule_duplicate_and_curriculum(): void
  {
    $courseId = $this->postJson('/api/v1/lms/courses', [
      'title' => 'Ops Foundations',
      'summary' => 'Short desc',
      'description' => 'Long desc',
      'is_free' => true,
      'is_featured' => true,
      'access_scope' => 'general',
      'certificate_enabled' => true,
      'certificate_requires_assessment_pass' => true,
      'certificate_auto_issue' => true,
      'seo_title' => 'Ops Foundations SEO',
    ])->assertCreated()
      ->assertJsonPath('data.course.status', 'draft')
      ->json('data.course.id');

    $course = Course::query()->where('uuid', $courseId)->firstOrFail();
    $this->assertNotEmpty($course->course_code);
    $this->assertStringStartsWith('KC-', $course->course_code);

    $moduleId = $this->postJson("/api/v1/lms/courses/{$courseId}/modules", [
      'title' => 'Module One',
      'status' => 'published',
    ])->assertCreated()->json('data.module.id');

    $lessonId = $this->postJson("/api/v1/lms/modules/{$moduleId}/lessons", [
      'title' => 'YouTube Lesson',
      'lesson_type' => 'youtube',
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'status' => 'published',
    ])->assertCreated()
      ->assertJsonPath('data.lesson.youtube_video_id', 'dQw4w9WgXcQ')
      ->json('data.lesson.id');

    $this->postJson("/api/v1/lms/lessons/{$lessonId}/duplicate")
      ->assertCreated()
      ->assertJsonPath('data.lesson.title', 'YouTube Lesson (Copy)');

    $this->postJson("/api/v1/lms/modules/{$moduleId}/duplicate")
      ->assertCreated();

    $this->postJson("/api/v1/lms/courses/{$courseId}/modules/reorder", [
      'items' => [
        ['id' => $moduleId, 'sort_order' => 2],
      ],
    ])->assertOk();

    $this->postJson("/api/v1/lms/modules/{$moduleId}/lessons/reorder", [
      'items' => [
        ['id' => $lessonId, 'sort_order' => 1],
      ],
    ])->assertOk();

    $this->postJson("/api/v1/lms/courses/{$courseId}/publish")
      ->assertOk()
      ->assertJsonPath('data.course.status', 'published');

    $this->postJson("/api/v1/lms/courses/{$courseId}/schedule", [
      'scheduled_publish_at' => now()->addDay()->toIso8601String(),
    ])->assertOk()
      ->assertJsonPath('data.course.status', 'coming_soon');

    $copyId = $this->postJson("/api/v1/lms/courses/{$courseId}/duplicate")
      ->assertCreated()
      ->assertJsonPath('data.course.status', 'draft')
      ->json('data.course.id');

    $this->assertNotSame($courseId, $copyId);
    $this->assertNotSame(
      Course::query()->where('uuid', $courseId)->value('course_code'),
      Course::query()->where('uuid', $copyId)->value('course_code'),
    );

    $this->postJson("/api/v1/lms/courses/{$courseId}/archive")
      ->assertOk()
      ->assertJsonPath('data.course.status', 'archived');

    $this->getJson('/api/v1/lms/import/schema')
      ->assertOk()
      ->assertJsonPath('data.importer.status', 'prepared');

    $this->postJson('/api/v1/lms/youtube/resolve', [
      'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ])->assertOk()
      ->assertJsonPath('data.youtube.youtube_video_id', 'dQw4w9WgXcQ');
  }

  public function test_scheduled_publish_command_publishes_due_courses(): void
  {
    $course = Course::query()->create([
      'title' => 'Scheduled Course',
      'slug' => 'scheduled-course-ops',
      'course_code' => 'KC-99991',
      'status' => CourseStatus::ComingSoon,
      'scheduled_publish_at' => now()->subMinute(),
      'is_free' => true,
    ]);

    $this->assertSame(0, Artisan::call('lms:publish-scheduled'));
    $course->refresh();
    $this->assertSame(CourseStatus::Published, $course->status);
    $this->assertNull($course->scheduled_publish_at);
  }

  public function test_youtube_metadata_service_extracts_ids(): void
  {
    $service = app(YoutubeMetadataService::class);
    $this->assertSame('abc123XYZ', $service->extractVideoId('https://youtu.be/abc123XYZ'));
    $this->assertSame('PLtest', $service->extractPlaylistId('https://www.youtube.com/playlist?list=PLtest'));
  }

  public function test_public_listing_hides_ministry_restricted_without_membership(): void
  {
    Course::query()->create([
      'title' => 'General Ops',
      'slug' => 'general-ops-m6h',
      'course_code' => 'KC-99992',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'access_scope' => 'general',
      'is_free' => true,
    ]);

    Course::query()->create([
      'title' => 'Ministry Only Ops',
      'slug' => 'ministry-only-ops-m6h',
      'course_code' => 'KC-99993',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'access_scope' => 'ministry',
      'is_free' => true,
    ]);

    $slugs = collect($this->getJson('/api/v1/public/courses')->assertOk()->json('data.data'))
      ->pluck('slug')
      ->all();

    $this->assertContains('general-ops-m6h', $slugs);
    $this->assertNotContains('ministry-only-ops-m6h', $slugs);
  }
}
