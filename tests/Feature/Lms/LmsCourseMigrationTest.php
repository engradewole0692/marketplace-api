<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Data\LegacyCourseLibrary;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Instructor;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\CourseMigrationService;
use App\Modules\Lms\Services\CourseMigrationVerificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Iam\IamTestCase;

final class LmsCourseMigrationTest extends IamTestCase
{
  public function test_migrates_youtube_library_resources_instructors_without_media_duplication(): void
  {
    $this->seedMigrationSources();

    $mediaBefore = CmsMedia::query()->count();

    $result = app(CourseMigrationService::class)->migrate();

    $this->assertSame(0, $result['stats']['media_uploads']);
    $this->assertGreaterThan(0, $result['stats']['courses_created']);
    $this->assertGreaterThan(0, $result['stats']['instructors_created']);
    $this->assertGreaterThan(0, $result['stats']['lessons_created']);
    $this->assertGreaterThan(0, $result['stats']['downloads_created']);
    $this->assertSame($mediaBefore, CmsMedia::query()->count());

    foreach (LegacyCourseLibrary::playlists() as $playlist) {
      $course = Course::query()->where('slug', $playlist['slug'])->first();
      $this->assertNotNull($course, "Missing course {$playlist['slug']}");
      $this->assertTrue((bool) $course->certificate_enabled);
      $this->assertGreaterThan(0, $course->lessons()->count());
      $this->assertTrue(
        Assessment::query()->where('course_id', $course->id)->where('status', 'published')->exists(),
      );
    }

    $resources = Course::query()->where('slug', LegacyCourseLibrary::resourcesCourseSlug())->first();
    $this->assertNotNull($resources);
    $this->assertGreaterThanOrEqual(2, $resources->downloads()->count());
    $this->assertTrue($resources->downloads()->whereNotNull('file_media_id')->exists());

    $lesson = Lesson::query()->where('slug', 'the-mandate-of-the-marketplace-minister')->first();
    $this->assertNotNull($lesson);
    $this->assertSame('youtube', $lesson->video_source->value);
    $this->assertNotEmpty($lesson->youtube_url);

    $this->assertTrue(Instructor::query()->where('slug', 'damola-adelakun')->exists());
  }

  public function test_does_not_rebuild_existing_courses_on_rerun(): void
  {
    $this->seedMigrationSources();
    app(CourseMigrationService::class)->migrate();

    $course = Course::query()->where('slug', 'foundations')->firstOrFail();
    $originalId = $course->id;
    $lessonCount = $course->lessons()->count();
    $course->update(['summary' => 'Custom operator summary — do not wipe']);

    $second = app(CourseMigrationService::class)->migrate();

    $this->assertSame(0, $second['stats']['courses_created']);
    $this->assertGreaterThan(0, $second['stats']['courses_skipped_existing']);
    $this->assertSame(0, $second['stats']['media_uploads']);

    $course->refresh();
    $this->assertSame($originalId, $course->id);
    $this->assertSame('Custom operator summary — do not wipe', $course->summary);
    $this->assertSame($lessonCount, $course->lessons()->count());
  }

  public function test_verification_passes_for_every_migrated_course(): void
  {
    $this->seedMigrationSources();
    app(CourseMigrationService::class)->migrate();

    $verify = app(CourseMigrationVerificationService::class)->verify($this->memberUser());

    $this->assertTrue($verify['passed'], json_encode($verify, JSON_PRETTY_PRINT));
    $this->assertSame(count(LegacyCourseLibrary::playlists()) + 1, $verify['summary']['total']);
  }

  public function test_artisan_migrate_and_verify_commands(): void
  {
    $this->seedMigrationSources();

    $this->assertSame(0, Artisan::call('lms:migrate-legacy-courses', ['--verify' => true]));
    $this->assertSame(0, Artisan::call('lms:verify-migrated-courses'));
  }

  public function test_public_enrollment_and_learner_lesson_payload_for_migrated_course(): void
  {
    $this->seedMigrationSources();
    app(CourseMigrationService::class)->migrate();

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/foundations/enroll')
      ->assertCreated()
      ->assertJsonPath('data.enrollment.status', 'active');

    $this->getJson('/api/v1/public/courses/foundations')
      ->assertOk()
      ->assertJsonPath('data.course.slug', 'foundations');

    $lesson = Lesson::query()->where('slug', 'the-mandate-of-the-marketplace-minister')->firstOrFail();
    $this->assertSame('youtube', $lesson->video_source->value);
  }

  private function seedMigrationSources(): void
  {
    $cover = CmsMedia::query()->create([
      'disk' => 'public',
      'path' => 'cms/catalog/seeded/resource/marketplace-ministers-handbook.webp',
      'name' => 'Handbook cover',
      'file_name' => 'marketplace-ministers-handbook.webp',
      'mime_type' => 'image/webp',
      'size' => 1200,
      'metadata' => ['seed_asset' => 'event-prayer', 'entity' => 'catalog_item'],
    ]);

    $fileA = CmsMedia::query()->create([
      'disk' => 'public',
      'path' => 'cms/catalog/seeded/resource-files/marketplace-ministers-handbook.txt',
      'name' => 'Marketplace Minister\'s Handbook',
      'file_name' => 'marketplace-ministers-handbook.txt',
      'mime_type' => 'text/plain',
      'size' => 400,
      'metadata' => ['kind' => 'seed_file'],
    ]);

    $fileB = CmsMedia::query()->create([
      'disk' => 'public',
      'path' => 'cms/catalog/seeded/resource-files/kingdom-leadership-playbook.txt',
      'name' => 'Kingdom Leadership Playbook',
      'file_name' => 'kingdom-leadership-playbook.txt',
      'mime_type' => 'text/plain',
      'size' => 500,
      'metadata' => ['kind' => 'seed_file'],
    ]);

    CmsCatalogItem::query()->create([
      'type' => CatalogItemType::Resource,
      'title' => 'Marketplace Minister\'s Handbook',
      'slug' => 'marketplace-ministers-handbook',
      'summary' => 'Foundational guide',
      'category' => 'Formation',
      'featured_media_id' => $cover->id,
      'is_active' => true,
      'published_at' => now(),
      'metadata' => [
        'file_media_id' => $fileA->id,
        'download_url' => '/storage/'.$fileA->path,
        'access_level' => 'free',
        'type' => 'book',
      ],
    ]);

    CmsCatalogItem::query()->create([
      'type' => CatalogItemType::Resource,
      'title' => 'Kingdom Leadership Playbook',
      'slug' => 'kingdom-leadership-playbook',
      'summary' => 'Leadership frameworks',
      'category' => 'Leadership',
      'is_active' => true,
      'published_at' => now(),
      'metadata' => [
        'file_media_id' => $fileB->id,
        'download_url' => '/storage/'.$fileB->path,
        'access_level' => 'members-only',
        'type' => 'pdf',
      ],
    ]);

    CmsLeadershipProfile::query()->create([
      'name' => 'Damola Adelakun',
      'slug' => 'damola-adelakun',
      'role' => 'Convener & Lead Visionary',
      'bio' => 'Entrepreneur and minister.',
      'photo_media_id' => $cover->id,
      'is_active' => true,
      'sort_order' => 1,
    ]);
  }
}
