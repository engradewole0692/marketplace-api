<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\PrayerTrainingImportService;
use Database\Seeders\LmsReferenceSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsPrayerTrainingImportTest extends IamTestCase
{
  private string $fixturePath;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed(LmsReferenceSeeder::class);
    $this->fixturePath = storage_path('framework/testing/prayer-training-fixture.xlsx');
    if (! is_dir(dirname($this->fixturePath))) {
      mkdir(dirname($this->fixturePath), 0777, true);
    }
    $this->buildFixtureSpreadsheet($this->fixturePath);
  }

  public function test_prayer_training_timetable_layout_from_real_spreadsheet_when_present(): void
  {
    $realPath = PrayerTrainingImportService::resolveDefaultImportPath();
    if ($realPath === null || ! is_readable($realPath)) {
      $this->markTestSkipped('Prayer Training spreadsheet not present at database/imports/.');
    }

    $service = app(PrayerTrainingImportService::class);
    $dryRun = $service->importFromPath($realPath, true);

    $this->assertSame(13, $dryRun['stats']['modules_created']);
    $this->assertSame(24, $dryRun['stats']['lessons_created']);
    $this->assertSame(1, $dryRun['stats']['assessment_created']);
    $this->assertSame(0, $dryRun['stats']['rows_failed']);

    $firstLesson = collect($dryRun['rows'])->firstWhere('type', 'lesson');
    $this->assertNotNull($firstLesson);
    $this->assertStringContainsString('Introduction to Prayer Leadership', (string) $firstLesson['title']);
    $this->assertStringContainsString('youtu.be/1TvQSTdiuQM', (string) $firstLesson['youtube_url']);

    $result = $service->importFromPath($realPath, false, $this->admin);
    $this->assertSame(0, $result['stats']['rows_failed']);

    $course = Course::query()->where('slug', PrayerTrainingImportService::COURSE_SLUG)->first();
    $this->assertNotNull($course);
    $this->assertSame(13, $course->modules()->count());
    $this->assertSame(24, Lesson::query()->where('course_id', $course->id)->count());
    $this->assertNull($course->primary_ministry_id);

    $this->postJson("/api/v1/lms/courses/{$course->uuid}/publish")->assertOk();

    $this->getJson('/api/v1/public/courses/prayer-training')
      ->assertOk()
      ->assertJsonPath('data.course.slug', 'prayer-training')
      ->assertJsonPath('data.course.title', 'Prayer Training');

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/prayer-training/enroll')
      ->assertCreated()
      ->assertJsonPath('data.enrollment.status', 'active');

    $lesson = Lesson::query()
      ->where('course_id', $course->id)
      ->where('slug', 'lesson-1')
      ->firstOrFail();

    $this->assertSame('1 - Introduction to Prayer Leadership; What is Prayer', $lesson->title);
    $this->assertStringContainsString('youtu.be/1TvQSTdiuQM', (string) $lesson->youtube_url);
  }

  public function test_prayer_training_import_is_idempotent_and_creates_exam_placeholder(): void
  {
    $service = app(PrayerTrainingImportService::class);

    $dryRun = $service->importFromPath($this->fixturePath, true);
    $this->assertTrue($dryRun['dry_run']);
    $this->assertSame(2, $dryRun['stats']['lessons_created']);
    $this->assertSame(1, $dryRun['stats']['assessment_created']);

    $first = $service->importFromPath($this->fixturePath, false, $this->admin);
    $this->assertSame(1, $first['stats']['course_created']);
    $this->assertSame(2, $first['stats']['modules_created']);
    $this->assertSame(2, $first['stats']['lessons_created']);
    $this->assertSame(1, $first['stats']['assessment_created']);

    $course = Course::query()->where('slug', PrayerTrainingImportService::COURSE_SLUG)->first();
    $this->assertNotNull($course);
    $this->assertNull($course->primary_ministry_id);
    $this->assertTrue((bool) ($course->metadata['needs_review'] ?? false));
    $this->assertSame('unassigned', $course->metadata['ministry_assignment'] ?? null);
    $this->assertSame(2, $course->modules()->count());
    $this->assertSame(2, Lesson::query()->where('course_id', $course->id)->count());

    $lesson = Lesson::query()->where('course_id', $course->id)->where('slug', 'lesson-1')->first();
    $this->assertNotNull($lesson);
    $this->assertSame('dQw4w9WgXcQ', $lesson->youtube_video_id);

    $assessment = Assessment::query()->where('course_id', $course->id)->where('slug', 'prayer-training-final-exam')->first();
    $this->assertNotNull($assessment);
    $this->assertTrue((bool) ($assessment->settings['needs_content_configuration'] ?? false));

    $second = $service->importFromPath($this->fixturePath, false, $this->admin);
    $this->assertSame(0, $second['stats']['course_created']);
    $this->assertSame(0, $second['stats']['lessons_created']);
    $this->assertGreaterThanOrEqual(1, $second['stats']['lessons_updated']);
  }

  public function test_admin_can_manage_enrollment_lifecycle(): void
  {
    $this->seed(LmsReferenceSeeder::class);
    $courseId = $this->postJson('/api/v1/lms/courses', [
      'title' => 'Lifecycle Course',
      'slug' => 'lifecycle-course',
      'is_free' => true,
      'visitor_free' => true,
    ])->assertCreated()->json('data.course.id');

    $this->postJson("/api/v1/lms/courses/{$courseId}/publish")->assertOk();

    $learner = $this->memberUser();
    $enrollmentId = $this->actingAs($learner)->postJson('/api/v1/public/courses/lifecycle-course/enroll')
      ->assertCreated()
      ->json('data.enrollment.id');

    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/lms/enrollments/{$enrollmentId}/lock", ['reason' => 'Review'])
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'locked');

    $this->postJson("/api/v1/lms/enrollments/{$enrollmentId}/restart")
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'active');

    $this->postJson("/api/v1/lms/enrollments/{$enrollmentId}/cancel")
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'cancelled');
  }

  private function buildFixtureSpreadsheet(string $path): void
  {
    $sheet = new Spreadsheet();
    $active = $sheet->getActiveSheet();
    $active->fromArray([
      ['Week', 'Lesson #', 'Title', 'YouTube URL'],
      ['1', '1', 'Prayer Foundations Part 1', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
      ['2', '2', 'Intercession Essentials', 'https://youtu.be/jNQXAC9IVRw'],
      ['', '', 'Exams', ''],
    ]);

    (new Xlsx($sheet))->save($path);
  }
}
