<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LmsCourseImport;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Services\LmsCourseImportService;
use Database\Seeders\LmsReferenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Iam\IamTestCase;

final class LmsCourseImportTest extends IamTestCase
{
  private string $fixturePath;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed(LmsReferenceSeeder::class);
    CourseCategory::query()->updateOrCreate(
      ['slug' => 'free-learning'],
      [
        'name' => 'Free Learning',
        'status' => 'active',
        'is_visible' => true,
        'is_free_learning_hub' => true,
        'sort_order' => 1,
      ],
    );
    $this->fixturePath = storage_path('framework/testing/course-import-fixture.xlsx');
    if (! is_dir(dirname($this->fixturePath))) {
      mkdir(dirname($this->fixturePath), 0777, true);
    }
  }

  public function test_valid_workbook_dry_run(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'FREE-001', 'course_title' => 'Free Course One']),
    ]);

    $response = $this->postJson('/api/v1/lms/import/courses/dry-run', [
      'file' => $file,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.dry_run.summary.total_rows', 1);
    $response->assertJsonPath('data.dry_run.summary.valid_rows', 1);
    $response->assertJsonPath('data.dry_run.rows.0.action', 'would_create');
  }

  public function test_dry_run_only_free_courses_skips_paid_school_rows(): void
  {
    LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-teachers',
      'title' => 'School of Teachers',
      'status' => SchoolStatus::Published,
      'published_at' => now(),
    ]);

    $file = $this->makeWorkbook([
      $this->schoolRow('School of Teachers', [
        'course_code' => 'KC-PAID-SKIP',
        'course_title' => 'Paid School Course Must Be Skipped',
      ]),
      $this->freeRow([
        'course_code' => 'KC-FREE-KEEP',
        'course_title' => 'Lesson 1: The Identity of an Intercessor',
        'free_category_name' => 'Intercessory Ministry',
      ]),
    ]);

    $response = $this->postJson('/api/v1/lms/import/courses/dry-run', [
      'file' => $file,
      'only_free_courses' => true,
      'create_missing_categories' => true,
      'create_missing_program_modules' => true,
    ]);

    $response->assertOk();
    $rows = collect($response->json('data.dry_run.rows'));
    $this->assertSame('skipped', $rows->firstWhere('course_code', 'KC-PAID-SKIP')['status'] ?? null);
    $this->assertNotSame('skipped', $rows->firstWhere('course_code', 'KC-FREE-KEEP')['status'] ?? 'skipped');
  }

  public function test_invalid_workbook_structure(): void
  {
    $path = storage_path('framework/testing/invalid-course-import.xlsx');
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['wrong_header'], null, 'A1');
    $sheet->fromArray(['value'], null, 'A2');
    (new Xlsx($spreadsheet))->save($path);

    $response = $this->postJson('/api/v1/lms/import/courses/dry-run', [
      'file' => new UploadedFile($path, 'invalid.xlsx', null, null, true),
    ]);

    $response->assertStatus(422);
  }

  public function test_missing_required_fields(): void
  {
    $file = $this->makeWorkbook([
      array_merge($this->freeRow(), ['course_title' => '']),
    ]);

    $service = app(LmsCourseImportService::class);
    $report = $service->importFromUpload($file, [], true, $this->admin);

    $this->assertSame(1, $report['summary']['invalid_rows']);
    $this->assertSame(1, $report['summary']['missing_required_fields']);
  }

  public function test_invalid_access_type(): void
  {
    $file = $this->makeWorkbook([
      array_merge($this->freeRow(), ['access_type' => 'premium']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['unsupported_values']);
  }

  public function test_paid_course_school_matching(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'marketplace-school',
      'title' => 'Marketplace School',
      'status' => 'published',
      'member_price' => 100,
      'public_price' => 150,
      'currency' => 'USD',
      'published_at' => now(),
    ]);

    $file = $this->makeWorkbook([
      $this->schoolRow($school->title, ['course_code' => 'SCH-001', 'course_title' => 'School Course A']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['schools_found']);
    $this->assertSame(0, $report['summary']['schools_missing']);
  }

  public function test_free_category_matching(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Prayer Hub',
      'slug' => 'prayer-hub',
      'status' => 'active',
      'is_free_learning_hub' => true,
    ]);

    $file = $this->makeWorkbook([
      $this->freeRow(['free_category_name' => $category->name, 'course_code' => 'FR-001']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['categories_found']);
  }

  public function test_programme_module_matching(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'module-school',
      'title' => 'Module School',
      'status' => 'published',
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'published_at' => now(),
    ]);
    LmsProgramModule::query()->create([
      'uuid' => (string) Str::uuid(),
      'container_type' => 'school',
      'school_id' => $school->id,
      'title' => 'Foundations',
      'slug' => 'foundations',
      'sort_order' => 1,
      'status' => 'published',
    ]);

    $file = $this->makeWorkbook([
      $this->schoolRow($school->title, [
        'program_module_name' => 'Foundations',
        'course_code' => 'MOD-001',
      ]),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['program_modules_found']);
  }

  public function test_missing_hierarchy_is_reported(): void
  {
    $file = $this->makeWorkbook([
      $this->schoolRow('Missing School Ltd', ['course_code' => 'MISS-001']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['schools_missing']);
    $this->assertSame(1, $report['summary']['invalid_rows']);
  }

  public function test_automatic_hierarchy_creation_when_enabled(): void
  {
    $file = $this->makeWorkbook([
      $this->schoolRow('Auto Created School', ['course_code' => 'AUTO-001']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [
      'create_missing_schools' => true,
    ], false, $this->admin);

    $this->assertSame(1, $report['summary']['imported']);
    $this->assertTrue(LmsSchool::query()->where('title', 'Auto Created School')->exists());
  }

  public function test_duplicate_course_detection_in_file(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'DUP-001']),
      $this->freeRow(['course_code' => 'DUP-001', 'course_title' => 'Duplicate Title']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['duplicate_rows']);
  }

  public function test_idempotent_repeated_import(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'IDEM-001', 'course_title' => 'Idempotent Course']),
    ]);

    $service = app(LmsCourseImportService::class);
    $first = $service->importFromUpload($file, [], false, $this->admin);
    $this->assertSame(1, $first['summary']['imported']);

    $secondFile = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'IDEM-001', 'course_title' => 'Idempotent Course']),
    ]);
    $second = $service->importFromUpload($secondFile, [], false, $this->admin);
    $this->assertSame(1, $second['summary']['unchanged']);
    $this->assertSame(1, Course::query()->where('course_code', 'IDEM-001')->count());
  }

  public function test_youtube_metadata_extraction(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow([
        'course_code' => 'YT-001',
        'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        'thumbnail_source' => 'youtube',
      ]),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], false, $this->admin);
    $this->assertSame(1, $report['summary']['imported']);

    $course = Course::query()->where('course_code', 'YT-001')->firstOrFail();
    $lesson = Lesson::query()->where('course_id', $course->id)->firstOrFail();
    $this->assertSame('dQw4w9WgXcQ', $lesson->youtube_video_id);
    $this->assertSame('youtube', $lesson->video_source->value ?? $lesson->video_source);
  }

  public function test_draft_import_by_default(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'DRF-001', 'status' => 'draft']),
    ]);

    app(LmsCourseImportService::class)->importFromUpload($file, [], false, $this->admin);
    $course = Course::query()->where('course_code', 'DRF-001')->firstOrFail();
    $this->assertSame(CourseStatus::Draft, $course->status);
  }

  public function test_free_only_import_skips_paid_school_rows_and_is_idempotent(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-teachers',
      'title' => 'School of Teachers',
      'status' => SchoolStatus::Published,
      'published_at' => now(),
    ]);
    $paidCount = Course::query()->where('school_id', $school->id)->count();

    $file = $this->makeWorkbook([
      $this->schoolRow('School of Teachers', [
        'course_code' => 'KC-PAID-1',
        'course_title' => 'Paid School Course Must Stay Untouched',
      ]),
      $this->freeRow([
        'course_code' => '',
        'course_title' => 'Lesson 1: The Identity of an Intercessor',
        'free_category_name' => 'Intercessory Ministry',
        'program_module_name' => 'Module 1',
        'module_order' => '1',
        'status' => '',
      ]),
      [
        'course_title' => '',
        'access_type' => '',
        'youtube_url' => 'https://www.youtube.com/watch?v=57HMXIkye6I',
      ],
    ]);

    $service = app(LmsCourseImportService::class);
    $settings = [
      'create_missing_schools' => false,
      'create_missing_categories' => true,
      'create_missing_program_modules' => true,
      'publish_after_import' => true,
      'only_access_types' => ['free'],
    ];

    $first = $service->importFromUpload($file, $settings, false, $this->admin);
    $paidRow = collect($first['rows'])->firstWhere('course_code', 'KC-PAID-1');
    $this->assertSame(
      'skipped',
      $paidRow['status'] ?? null,
      json_encode(['summary' => $first['summary'], 'paid' => $paidRow], JSON_UNESCAPED_SLASHES),
    );
    $this->assertSame(1, $first['summary']['imported']);
    $this->assertGreaterThanOrEqual(1, $first['summary']['skipped']);
    $this->assertSame($paidCount, Course::query()->where('school_id', $school->id)->count());
    $this->assertNull(Course::query()->where('course_code', 'KC-PAID-1')->first());

    $category = CourseCategory::query()->where('name', 'Intercessory Ministry')->firstOrFail();
    $this->assertTrue((bool) $category->is_free_learning_hub);
    $course = Course::query()->where('title', 'Lesson 1: The Identity of an Intercessor')->firstOrFail();
    $this->assertNull($course->school_id);
    $this->assertSame($category->id, $course->category_id);
    $this->assertNotNull($course->program_module_id);
    $this->assertSame('Module 1', $course->programModule?->title);
    $this->assertSame(1, (int) $course->programModule?->sort_order);

    $second = $service->importFromUpload($file, $settings, false, $this->admin);
    $this->assertSame(1, Course::query()->where('title', 'Lesson 1: The Identity of an Intercessor')->count());
    $this->assertSame(0, (int) ($second['summary']['imported'] ?? 0));
    $this->assertGreaterThanOrEqual(1, (int) ($second['summary']['unchanged'] + $second['summary']['updated']));

    $unresolved = collect($first['rows'])->first(
      fn (array $row) => ($row['access_type'] ?? '') === '' && ($row['status'] ?? '') === 'invalid',
    );
    $this->assertNotNull($unresolved);
  }

  public function test_artisan_free_course_import_skips_paid_school_rows(): void
  {
    $this->makeWorkbook([
      $this->schoolRow('School of Teachers', [
        'course_code' => 'ARTISAN-PAID',
        'course_title' => 'Paid School Course Via Artisan',
      ]),
      $this->freeRow([
        'course_code' => 'ARTISAN-FREE',
        'course_title' => 'Lesson 2: The Call of an Intercessor',
        'free_category_name' => 'Intercessory Ministry',
        'program_module_name' => 'Module 2',
        'module_order' => '2',
      ]),
    ]);

    $this->artisan('lms:import-free-courses', [
      'path' => $this->fixturePath,
      '--publish' => true,
    ])->assertSuccessful();

    $this->assertNull(Course::query()->where('course_code', 'ARTISAN-PAID')->first());
    $free = Course::query()->where('course_code', 'ARTISAN-FREE')->firstOrFail();
    $this->assertNull($free->school_id);
    $this->assertSame('Intercessory Ministry', $free->category?->name);
  }

  public function test_published_import_when_requested(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'PUB-001', 'status' => 'published']),
    ]);

    app(LmsCourseImportService::class)->importFromUpload($file, [
      'publish_after_import' => true,
    ], false, $this->admin);

    $course = Course::query()->where('course_code', 'PUB-001')->firstOrFail();
    $this->assertSame(CourseStatus::Published, $course->status);
  }

  public function test_existing_course_update(): void
  {
    $category = CourseCategory::query()->where('is_free_learning_hub', true)->firstOrFail();
    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'course_code' => 'UPD-001',
      'title' => 'Old Title',
      'slug' => 'old-title',
      'status' => CourseStatus::Draft,
      'is_free' => true,
    ]);

    $file = $this->makeWorkbook([
      $this->freeRow([
        'course_code' => 'UPD-001',
        'course_title' => 'Updated Title',
        'free_category_name' => $category->name,
      ]),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], false, $this->admin);
    $this->assertSame(1, $report['summary']['updated']);
    $this->assertSame('Updated Title', $course->fresh()->title);
  }

  public function test_import_report_and_history_endpoint(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'HIST-001']),
    ]);

    $response = $this->postJson('/api/v1/lms/import/courses/run', ['file' => $file]);
    $response->assertOk();
    $response->assertJsonPath('data.import.summary.imported', 1);

    $history = $this->getJson('/api/v1/lms/import/courses/history');
    $history->assertOk();
    $this->assertGreaterThan(0, count($history->json('data.data')));

    $importId = $history->json('data.data.0.id');
    $this->getJson("/api/v1/lms/import/courses/history/{$importId}")->assertOk();
  }

  public function test_authorization_required(): void
  {
    $member = $this->memberUser();
    Sanctum::actingAs($member);
    $file = $this->makeWorkbook([$this->freeRow()]);

    $this->postJson('/api/v1/lms/import/courses/dry-run', ['file' => $file])
      ->assertForbidden();
  }

  public function test_invalid_youtube_url_rejected(): void
  {
    $file = $this->makeWorkbook([
      array_merge($this->freeRow(), ['youtube_url' => 'https://example.com/not-youtube']),
    ]);

    $report = app(LmsCourseImportService::class)->importFromUpload($file, [], true, $this->admin);
    $this->assertSame(1, $report['summary']['invalid_youtube_urls']);
  }

  public function test_lesson_uses_seventy_five_percent_threshold(): void
  {
    $file = $this->makeWorkbook([
      $this->freeRow(['course_code' => 'THR-001']),
    ]);

    app(LmsCourseImportService::class)->importFromUpload($file, [], false, $this->admin);
    $course = Course::query()->where('course_code', 'THR-001')->firstOrFail();
    $lesson = Lesson::query()->where('course_id', $course->id)->firstOrFail();
    $this->assertSame(75, (int) $lesson->completion_threshold_percent);
  }

  public function test_template_download_endpoint(): void
  {
    $this->get('/api/v1/lms/import/courses/template')
      ->assertOk()
      ->assertHeader('content-disposition');
  }

  /** @param  list<array<string, string>>  $rows */
  private function makeWorkbook(array $rows): UploadedFile
  {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(LmsCourseImportService::SHEET_NAME);
    $sheet->fromArray(LmsCourseImportService::TEMPLATE_HEADERS, null, 'A1');

    $line = 2;
    foreach ($rows as $row) {
      $ordered = [];
      foreach (LmsCourseImportService::TEMPLATE_HEADERS as $header) {
        $ordered[] = $row[$header] ?? $this->defaultValue($header);
      }
      $sheet->fromArray($ordered, null, 'A'.$line);
      $line++;
    }

    (new Xlsx($spreadsheet))->save($this->fixturePath);

    return new UploadedFile($this->fixturePath, 'course-import.xlsx', null, null, true);
  }

  /** @return array<string, string> */
  private function freeRow(array $overrides = []): array
  {
    $category = CourseCategory::query()->where('is_free_learning_hub', true)->first();
    $this->assertNotNull($category);

    return array_merge([
      'course_code' => 'FREE-'.Str::upper(Str::random(4)),
      'course_title' => 'Imported Free Course',
      'course_description' => 'Description',
      'access_type' => 'free',
      'school_name' => '',
      'free_category_name' => $category->name,
      'program_module_name' => '',
      'module_order' => '',
      'course_order' => '1',
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'video_upload_path' => '',
      'duration_minutes' => '30',
      'thumbnail_source' => 'youtube',
      'thumbnail_url' => '',
      'estimated_duration' => '30',
      'price_member' => '',
      'price_non_member' => '',
      'currency' => 'USD',
      'certificate_enabled' => 'true',
      'certificate_trigger' => '',
      'assessment_enabled' => 'false',
      'assessment_trigger' => '',
      'assignment_enabled' => 'false',
      'assignment_trigger' => '',
      'resources' => '',
      'status' => 'draft',
    ], $overrides);
  }

  /** @return array<string, string> */
  private function schoolRow(string $schoolName, array $overrides = []): array
  {
    return array_merge($this->freeRow([
      'access_type' => 'school',
      'school_name' => $schoolName,
      'free_category_name' => '',
    ]), $overrides);
  }

  private function defaultValue(string $header): string
  {
    return match ($header) {
      'access_type' => 'free',
      'video_source' => 'youtube',
      'thumbnail_source' => 'youtube',
      'status' => 'draft',
      'certificate_enabled' => 'true',
      'assessment_enabled' => 'false',
      'assignment_enabled' => 'false',
      'currency' => 'USD',
      default => '',
    };
  }
}
