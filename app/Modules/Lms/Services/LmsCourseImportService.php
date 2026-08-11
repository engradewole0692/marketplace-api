<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LmsCourseImport;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * General reusable LMS course importer — one row per course with hierarchy + video metadata.
 */
final class LmsCourseImportService implements ServiceContract
{
  public const SHEET_NAME = 'Courses';

  public const TEMPLATE_FILENAME = 'Kingdom_Collective_LMS_Course_Import_Template.xlsx';

  /** @var list<string> */
  public const TEMPLATE_HEADERS = [
    'course_code',
    'course_title',
    'course_description',
    'access_type',
    'school_name',
    'free_category_name',
    'program_module_name',
    'module_order',
    'course_order',
    'video_source',
    'youtube_url',
    'video_upload_path',
    'duration_minutes',
    'thumbnail_source',
    'thumbnail_url',
    'estimated_duration',
    'price_member',
    'price_non_member',
    'currency',
    'certificate_enabled',
    'certificate_trigger',
    'assessment_enabled',
    'assessment_trigger',
    'assignment_enabled',
    'assignment_trigger',
    'resources',
    'status',
  ];

  public function __construct(
    private readonly CourseService $courses,
    private readonly ModuleService $modules,
    private readonly LessonService $lessons,
    private readonly SchoolService $schools,
    private readonly CategoryService $categories,
    private readonly ProgramModuleService $programModules,
    private readonly YoutubeMetadataService $youtube,
    private readonly LmsAuditService $audit,
  ) {}

  /**
   * @param  array<string, mixed>  $settings
   * @return array<string, mixed>
   */
  public function importFromUpload(
    UploadedFile $file,
    array $settings,
    bool $dryRun,
    User $actor,
    ?LmsCourseImport $importRecord = null,
  ): array {
    $this->assertValidUpload($file);
    $rows = $this->readWorkbook($file);
    $normalizedSettings = $this->normalizeSettings($settings);

    return $this->processRows(
      $rows,
      $normalizedSettings,
      $dryRun,
      $actor,
      $file->getClientOriginalName(),
      $importRecord,
    );
  }

  public function downloadTemplate(): StreamedResponse
  {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(self::SHEET_NAME);
    $sheet->fromArray(self::TEMPLATE_HEADERS, null, 'A1');
    $sheet->fromArray($this->sampleDataRow(), null, 'A2');
    $writer = new Xlsx($spreadsheet);

    return response()->streamDownload(function () use ($writer): void {
      $writer->save('php://output');
    }, self::TEMPLATE_FILENAME, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function schema(): array
  {
    return [
      'name' => 'Kingdom Collective LMS Course Import',
      'version' => '1.0',
      'sheet' => self::SHEET_NAME,
      'template_filename' => self::TEMPLATE_FILENAME,
      'headers' => self::TEMPLATE_HEADERS,
      'access_types' => ['school', 'free'],
      'video_sources' => ['youtube', 'upload', 'none'],
      'thumbnail_sources' => ['youtube', 'url', 'upload', 'none'],
      'statuses' => ['draft', 'published'],
      'default_settings' => [
        'create_missing_schools' => false,
        'create_missing_categories' => false,
        'create_missing_program_modules' => false,
        'publish_after_import' => false,
      ],
    ];
  }

  private function assertValidUpload(UploadedFile $file): void
  {
    $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
    if (! in_array($ext, ['xlsx', 'xls'], true)) {
      throw new \InvalidArgumentException('Import file must be an Excel workbook (.xlsx).');
    }
    if ($file->getSize() > 10 * 1024 * 1024) {
      throw new \InvalidArgumentException('Import file exceeds the 10 MB limit.');
    }
  }

  /**
   * @return list<array<string, string>>
   */
  private function readWorkbook(UploadedFile $file): array
  {
    $spreadsheet = IOFactory::load($file->getRealPath());
    $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME) ?? $spreadsheet->getActiveSheet();
    $matrix = $sheet->toArray(null, true, true, false);
    if ($matrix === []) {
      throw new \InvalidArgumentException('Workbook is empty.');
    }

    $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), array_shift($matrix) ?? []);
    $required = ['course_title', 'access_type'];
    foreach ($required as $header) {
      if (! in_array($header, $headers, true)) {
        throw new \InvalidArgumentException(
          "Invalid workbook structure: missing required column '{$header}' on sheet '".self::SHEET_NAME."'.",
        );
      }
    }

    return $this->matrixToAssoc($headers, $matrix);
  }

  /**
   * @param  list<string>  $headers
   * @param  list<list<mixed>>  $matrix
   * @return list<array<string, string>>
   */
  private function matrixToAssoc(array $headers, array $matrix): array
  {
    $rows = [];
    foreach ($matrix as $line) {
      if ($this->rowIsEmpty($line)) {
        continue;
      }
      $assoc = [];
      foreach ($headers as $i => $key) {
        if ($key === '') {
          continue;
        }
        $assoc[$key] = trim((string) ($line[$i] ?? ''));
      }
      $rows[] = $assoc;
    }

    return $rows;
  }

  /** @param  list<mixed>  $line */
  private function rowIsEmpty(array $line): bool
  {
    foreach ($line as $cell) {
      if (trim((string) $cell) !== '') {
        return false;
      }
    }

    return true;
  }

  /**
   * @param  list<array<string, string>>  $rows
   * @param  array<string, bool>  $settings
   * @return array<string, mixed>
   */
  private function processRows(
    array $rows,
    array $settings,
    bool $dryRun,
    User $actor,
    string $sourceFilename,
    ?LmsCourseImport $importRecord,
  ): array {
    $summary = $this->emptySummary(count($rows));
    $rowReports = [];
    $seenCodes = [];
    $seenIdentity = [];
    $parsedRows = [];

    foreach ($rows as $index => $raw) {
      $rowNumber = $index + 2;
      $parsed = $this->parseRow($raw, $rowNumber, $settings, $dryRun, $actor);
      $parsedRows[] = $parsed;

      if ($parsed['status'] === 'invalid') {
        $summary['invalid_rows']++;
        $this->incrementIssueCounters($summary, $parsed);
        $rowReports[] = $this->rowReport($parsed);
        continue;
      }

      $summary['valid_rows']++;

      if ($parsed['course_code'] !== '' && isset($seenCodes[$parsed['course_code']])) {
        $summary['duplicate_rows']++;
        $parsed['status'] = 'duplicate';
        $parsed['action'] = 'skipped';
        $parsed['message'] = 'Duplicate course_code in this file.';
        $parsedRows[$index] = $parsed;
        $rowReports[] = $this->rowReport($parsed);
        continue;
      }

      $identityKey = $parsed['identity_key'];
      if ($identityKey !== '' && isset($seenIdentity[$identityKey])) {
        $summary['duplicate_rows']++;
        $parsed['status'] = 'duplicate';
        $parsed['action'] = 'skipped';
        $parsed['message'] = 'Duplicate course identity in this file.';
        $parsedRows[$index] = $parsed;
        $rowReports[] = $this->rowReport($parsed);
        continue;
      }

      if ($parsed['course_code'] !== '') {
        $seenCodes[$parsed['course_code']] = true;
      }
      $seenIdentity[$identityKey] = true;

      if ($parsed['existing_course']) {
        $summary['existing_courses']++;
      } else {
        $summary['new_courses']++;
      }

      $this->trackHierarchyCounts($summary, $parsed);

      if ($parsed['status'] === 'invalid_hierarchy') {
        $summary['invalid_rows']++;
        $summary['valid_rows']--;
        $parsedRows[$index] = $parsed;
        $rowReports[] = $this->rowReport($parsed);
        continue;
      }

      $parsed['action'] = match (true) {
        $dryRun => $parsed['existing_course'] ? 'would_update' : 'would_create',
        default => null,
      };
      $parsedRows[$index] = $parsed;
      $rowReports[] = $this->rowReport($parsed);
    }

    if (! $dryRun) {
      DB::transaction(function () use ($parsedRows, $settings, $actor, &$summary, &$rowReports, $sourceFilename): void {
        $imported = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($parsedRows as $i => $parsed) {
          if ($parsed['status'] === 'duplicate') {
            $skipped++;
            continue;
          }

          if (! in_array($parsed['status'], ['valid'], true)) {
            if ($parsed['status'] === 'invalid' || $parsed['status'] === 'invalid_hierarchy') {
              $failed++;
            }
            continue;
          }

          $outcome = $this->importParsedRow($parsed, $settings, $actor);
          $parsedRows[$i] = array_merge($parsed, $outcome);
          $parsedRows[$i]['course'] = $outcome['course'] ?? null;
          $parsedRows[$i]['action'] = $outcome['action'];
          $parsedRows[$i]['message'] = $outcome['message'];
          $parsedRows[$i]['status'] = 'imported';
          $rowReports[$i] = $this->rowReport($parsedRows[$i]);

          match ($outcome['action']) {
            'created' => $imported++,
            'updated' => $updated++,
            'unchanged' => $unchanged++,
            'skipped' => $skipped++,
            default => $failed++,
          };
        }

        $summary['imported'] = $imported;
        $summary['updated'] = $updated;
        $summary['unchanged'] = $unchanged;
        $summary['skipped'] = $skipped + $summary['duplicate_rows'];
        $summary['failed'] = $failed + $summary['invalid_rows'];

        $this->audit->record(
          new Course,
          $actor,
          'course.import.completed',
          'Course spreadsheet import completed.',
          null,
          ['source' => $sourceFilename, 'summary' => $summary],
        );
      });
    } else {
      $summary['imported'] = 0;
      $summary['updated'] = 0;
      $summary['unchanged'] = 0;
      $summary['skipped'] = $summary['duplicate_rows'];
      $summary['failed'] = $summary['invalid_rows'];
    }

    $report = [
      'dry_run' => $dryRun,
      'filename' => $sourceFilename,
      'summary' => $summary,
      'rows' => $rowReports,
      'settings' => $settings,
    ];

    if ($importRecord !== null && ! $dryRun) {
      $importRecord->fill([
        'status' => ($summary['failed'] ?? 0) > 0 ? 'failed' : 'completed',
        'summary' => $summary,
        'report' => $report,
      ])->save();
    }

    return $report;
  }

  /**
   * @param  array<string, string>  $raw
   * @param  array<string, bool>  $settings
   * @return array<string, mixed>
   */
  private function parseRow(array $raw, int $rowNumber, array $settings, bool $dryRun, User $actor): array
  {
    $title = $raw['course_title'] ?? '';
    $accessType = strtolower(trim($raw['access_type'] ?? ''));

    $parsed = [
      'row' => $rowNumber,
      'course_code' => trim($raw['course_code'] ?? ''),
      'course_title' => $title,
      'course_description' => $raw['course_description'] ?? '',
      'access_type' => $accessType,
      'school_name' => trim($raw['school_name'] ?? ''),
      'free_category_name' => trim($raw['free_category_name'] ?? ''),
      'program_module_name' => trim($raw['program_module_name'] ?? ''),
      'module_order' => $this->intOrNull($raw['module_order'] ?? ''),
      'course_order' => $this->intOrNull($raw['course_order'] ?? ''),
      'video_source' => strtolower(trim($raw['video_source'] ?? 'youtube')),
      'youtube_url' => trim($raw['youtube_url'] ?? ''),
      'video_upload_path' => trim($raw['video_upload_path'] ?? ''),
      'duration_minutes' => $this->intOrNull($raw['duration_minutes'] ?? ''),
      'thumbnail_source' => strtolower(trim($raw['thumbnail_source'] ?? 'none')),
      'thumbnail_url' => trim($raw['thumbnail_url'] ?? ''),
      'estimated_duration' => $this->intOrNull($raw['estimated_duration'] ?? ''),
      'price_member' => $this->decimalOrNull($raw['price_member'] ?? ''),
      'price_non_member' => $this->decimalOrNull($raw['price_non_member'] ?? ''),
      'currency' => strtoupper(trim($raw['currency'] ?? 'USD')) ?: 'USD',
      'certificate_enabled' => $this->boolValue($raw['certificate_enabled'] ?? 'true'),
      'assessment_enabled' => $this->boolValue($raw['assessment_enabled'] ?? 'false'),
      'assignment_enabled' => $this->boolValue($raw['assignment_enabled'] ?? 'false'),
      'resources' => $this->parseResources($raw['resources'] ?? ''),
      'status' => 'valid',
      'action' => null,
      'message' => 'OK',
      'existing_course' => null,
      'school' => null,
      'category' => null,
      'program_module' => null,
      'youtube_meta' => null,
      'identity_key' => '',
      'issues' => [],
    ];

    if ($title === '') {
      return $this->invalidate($parsed, 'missing_required_fields', 'Missing required field: course_title.');
    }

    if (! in_array($accessType, ['school', 'free'], true)) {
      return $this->invalidate($parsed, 'unsupported_values', "Invalid access_type '{$accessType}'. Use school or free.");
    }

    if ($accessType === 'school' && $parsed['school_name'] === '') {
      return $this->invalidate($parsed, 'missing_required_fields', 'school_name is required when access_type is school.');
    }

    if ($accessType === 'free' && $parsed['free_category_name'] === '') {
      return $this->invalidate($parsed, 'missing_required_fields', 'free_category_name is required when access_type is free.');
    }

    if ($accessType === 'school' && $parsed['free_category_name'] !== '') {
      $parsed['issues'][] = 'free_category_name ignored for school courses.';
    }

    if ($accessType === 'free' && $parsed['school_name'] !== '') {
      return $this->invalidate($parsed, 'unsupported_values', 'Free courses cannot be assigned to a school.');
    }

    if (! in_array($parsed['video_source'], ['youtube', 'upload', 'none'], true)) {
      return $this->invalidate($parsed, 'unsupported_values', "Unsupported video_source '{$parsed['video_source']}'.");
    }

    if ($parsed['video_source'] === 'youtube') {
      if ($parsed['youtube_url'] === '') {
        return $this->invalidate($parsed, 'missing_required_fields', 'youtube_url is required when video_source is youtube.');
      }
      $videoId = $this->youtube->extractVideoId($parsed['youtube_url']);
      if ($videoId === null) {
        return $this->invalidate($parsed, 'invalid_youtube_urls', 'Invalid YouTube URL.');
      }
      $meta = $this->youtube->resolve($parsed['youtube_url']);
      $parsed['youtube_meta'] = $meta;
      if ($parsed['duration_minutes'] === null && $meta['duration_minutes'] !== null) {
        $parsed['duration_minutes'] = $meta['duration_minutes'];
      }
    }

    if ($parsed['thumbnail_source'] === 'youtube' && $parsed['youtube_meta'] !== null) {
      $parsed['thumbnail_url'] = $parsed['youtube_meta']['thumbnail_url'] ?? $parsed['thumbnail_url'];
    }

    if ($parsed['thumbnail_source'] === 'url' && $parsed['thumbnail_url'] !== '') {
      if (! filter_var($parsed['thumbnail_url'], FILTER_VALIDATE_URL)) {
        return $this->invalidate($parsed, 'unsupported_values', 'Invalid thumbnail_url.');
      }
    }

    $statusRaw = strtolower(trim($raw['status'] ?? 'draft'));
    if ($statusRaw !== '' && ! in_array($statusRaw, ['draft', 'published'], true)) {
      return $this->invalidate($parsed, 'unsupported_values', "Unsupported status '{$statusRaw}'.");
    }
    $parsed['import_status'] = $statusRaw !== '' ? $statusRaw : 'draft';

    $school = null;
    $category = null;
    $programModule = null;

    if ($accessType === 'school') {
      $school = $this->resolveSchool($parsed['school_name'], $settings, $dryRun, $actor);
      if ($school === false) {
        return $this->invalidateHierarchy($parsed, "School not found: '{$parsed['school_name']}'.");
      }
      $parsed['school'] = $school;
    } else {
      $category = $this->resolveCategory($parsed['free_category_name'], $settings, $dryRun, $actor);
      if ($category === false) {
        return $this->invalidateHierarchy($parsed, "Free category not found: '{$parsed['free_category_name']}'.");
      }
      $parsed['category'] = $category;
    }

    if ($parsed['program_module_name'] !== '') {
      $programModule = $this->resolveProgramModule(
        $parsed['program_module_name'],
        $school,
        $category,
        $parsed['module_order'],
        $settings,
        $dryRun,
        $actor,
      );
      if ($programModule === false) {
        return $this->invalidateHierarchy($parsed, "Programme module not found: '{$parsed['program_module_name']}'.");
      }
      $parsed['program_module'] = $programModule;
    }

    $existing = $this->findExistingCourse($parsed, $school, $category);
    $parsed['existing_course'] = $existing;
    $parsed['identity_key'] = $this->identityKey($parsed, $school, $category);

    return $parsed;
  }

  /**
   * @param  array<string, mixed>  $parsed
   * @param  array<string, bool>  $settings
   * @return array{action: string, course?: Course, message: string}
   */
  private function importParsedRow(array $parsed, array $settings, User $actor): array
  {
    /** @var LmsSchool|null $school */
    $school = $parsed['school'];
    /** @var CourseCategory|null $category */
    $category = $parsed['category'];
    /** @var LmsProgramModule|null $programModule */
    $programModule = $parsed['program_module'];
    /** @var Course|null $existing */
    $existing = $parsed['existing_course'];

    $coursePayload = $this->buildCoursePayload($parsed, $school, $category, $programModule, $settings);
    $desiredSnapshot = $this->courseSnapshotFromPayload($coursePayload, $parsed);

    if ($existing instanceof Course) {
      $existing->load(['modules.lessons']);
      if ($this->coursesMatchSnapshot($existing, $desiredSnapshot, $parsed)) {
        return ['action' => 'unchanged', 'course' => $existing, 'message' => 'Course unchanged.'];
      }
      $course = $this->courses->update($existing, $coursePayload, $actor);
      $this->syncCurriculum($course, $parsed, $actor, false);

      return ['action' => 'updated', 'course' => $course->fresh(['modules.lessons', 'school', 'category', 'programModule']), 'message' => 'Course updated.'];
    }

    $course = $this->courses->create($coursePayload, $actor);
    if ($programModule instanceof LmsProgramModule) {
      $this->programModules->assignCourse($programModule, $course);
    }
    $this->syncCurriculum($course, $parsed, $actor, true);

    if ($settings['publish_after_import'] || ($parsed['import_status'] ?? 'draft') === 'published') {
      $this->courses->publish($course, $actor);
    }

    return ['action' => 'created', 'course' => $course->fresh(['modules.lessons', 'school', 'category', 'programModule']), 'message' => 'Course created.'];
  }

  /**
   * @param  array<string, mixed>  $parsed
   * @return array<string, mixed>
   */
  private function buildCoursePayload(
    array $parsed,
    ?LmsSchool $school,
    ?CourseCategory $category,
    ?LmsProgramModule $programModule,
    array $settings,
  ): array {
    $isFree = $parsed['access_type'] === 'free';
    $status = ($settings['publish_after_import'] || ($parsed['import_status'] ?? 'draft') === 'published')
      ? CourseStatus::Published->value
      : CourseStatus::Draft->value;

    $metadata = [
      'import' => array_filter([
        'source' => 'course_import',
        'thumbnail_url' => $parsed['thumbnail_url'] !== '' ? $parsed['thumbnail_url'] : null,
        'row' => $parsed['row'],
      ]),
    ];

    $payload = [
      'title' => $parsed['course_title'],
      'description' => $parsed['course_description'] !== '' ? $parsed['course_description'] : null,
      'summary' => Str::limit(strip_tags($parsed['course_description']), 500),
      'status' => $status,
      'is_free' => $isFree,
      'visitor_free' => $isFree,
      'member_free' => $isFree,
      'certificate_enabled' => $parsed['certificate_enabled'],
      'assessment_required' => $parsed['assessment_enabled'],
      'assignment_required' => $parsed['assignment_enabled'],
      'duration_minutes' => $parsed['duration_minutes'],
      'estimated_completion_minutes' => $parsed['estimated_duration'] ?? $parsed['duration_minutes'],
      'currency' => $parsed['currency'],
      'metadata' => $metadata,
      'sort_order' => $parsed['course_order'] ?? 0,
    ];

    if ($parsed['course_code'] !== '') {
      $payload['course_code'] = $parsed['course_code'];
    }

    if ($school instanceof LmsSchool) {
      $payload['school_id'] = $school->uuid;
    }

    if ($category instanceof CourseCategory) {
      $payload['category_id'] = $category->uuid;
    }

    if ($programModule instanceof LmsProgramModule) {
      $payload['program_module_id'] = $programModule->uuid;
    }

    if ($parsed['price_member'] !== null || $parsed['price_non_member'] !== null) {
      $payload['member_price'] = $parsed['price_member'] ?? 0;
      $payload['public_price'] = $parsed['price_non_member'] ?? 0;
    } elseif ($isFree) {
      $payload['member_price'] = 0;
      $payload['public_price'] = 0;
    }

    return $payload;
  }

  private function syncCurriculum(Course $course, array $parsed, User $actor, bool $isNew): void
  {
    $course->loadMissing(['modules.lessons']);
    $module = $course->modules->first();
    if ($module === null) {
      $module = $this->modules->create($course, [
        'title' => 'Course Content',
        'status' => ModuleStatus::Published->value,
        'sort_order' => 1,
      ], $actor);
    }

    $lessonData = [
      'title' => $parsed['course_title'],
      'lesson_type' => LessonType::Video->value,
      'status' => ModuleStatus::Published->value,
      'duration_minutes' => $parsed['duration_minutes'],
      'completion_threshold_percent' => 75,
      'is_mandatory' => true,
      'sort_order' => 1,
    ];

    if ($parsed['video_source'] === 'youtube' && $parsed['youtube_url'] !== '') {
      $meta = $parsed['youtube_meta'] ?? $this->youtube->resolve($parsed['youtube_url']);
      $lessonData['video_source'] = VideoSource::Youtube->value;
      $lessonData['youtube_url'] = $meta['youtube_url'] ?? $parsed['youtube_url'];
      $lessonData['youtube_video_id'] = $meta['youtube_video_id'] ?? $this->youtube->extractVideoId($parsed['youtube_url']);
    } elseif ($parsed['video_source'] === 'none') {
      $lessonData['video_source'] = VideoSource::None->value;
    }

    if ($parsed['resources'] !== []) {
      $lessonData['resources'] = array_map(fn ($url, $i) => [
        'title' => 'Resource '.($i + 1),
        'resource_type' => 'link',
        'external_url' => $url,
        'sort_order' => $i,
        'is_downloadable' => false,
        'access_level' => 'enrolled',
      ], $parsed['resources'], array_keys($parsed['resources']));
    }

    $lesson = $module->lessons()->where('slug', Str::slug($parsed['course_title']))->first();
    if ($lesson instanceof Lesson) {
      $this->lessons->update($lesson, $lessonData, $actor);
    } else {
      $this->lessons->create($module, $lessonData, $actor);
    }
  }

  private function findExistingCourse(array $parsed, ?LmsSchool $school, ?CourseCategory $category): ?Course
  {
    if ($parsed['course_code'] !== '') {
      $byCode = Course::query()->where('course_code', $parsed['course_code'])->first();
      if ($byCode) {
        return $byCode;
      }
    }

    $slug = Str::slug($parsed['course_title']);
    $query = Course::query()->where('slug', $slug);
    if ($school instanceof LmsSchool) {
      $query->where('school_id', $school->id);
    } elseif ($category instanceof CourseCategory) {
      $query->where('category_id', $category->id)->whereNull('school_id');
    }

    return $query->first();
  }

  private function identityKey(array $parsed, ?LmsSchool $school, ?CourseCategory $category): string
  {
    if ($parsed['course_code'] !== '') {
      return 'code:'.$parsed['course_code'];
    }

    $scope = $school ? 'school:'.$school->id : 'category:'.($category?->id ?? 'none');

    return $scope.':slug:'.Str::slug($parsed['course_title']);
  }

  private function resolveSchool(string $name, array $settings, bool $dryRun, User $actor): LmsSchool|false|null
  {
    $school = LmsSchool::query()
      ->where(fn ($q) => $q->where('title', $name)->orWhere('slug', Str::slug($name)))
      ->first();

    if ($school) {
      return $school;
    }

    if (! $settings['create_missing_schools']) {
      return false;
    }

    if ($dryRun) {
      return null;
    }

    return $this->schools->create([
      'title' => $name,
      'slug' => Str::slug($name),
      'status' => 'draft',
    ], $actor);
  }

  private function resolveCategory(string $name, array $settings, bool $dryRun, User $actor): CourseCategory|false|null
  {
    $category = CourseCategory::query()
      ->where('is_free_learning_hub', true)
      ->where(fn ($q) => $q->where('name', $name)->orWhere('slug', Str::slug($name)))
      ->first();

    if ($category) {
      return $category;
    }

    if (! $settings['create_missing_categories']) {
      return false;
    }

    if ($dryRun) {
      return null;
    }

    return $this->categories->create([
      'name' => $name,
      'slug' => Str::slug($name),
      'is_free_learning_hub' => true,
      'status' => 'active',
    ], $actor);
  }

  private function resolveProgramModule(
    string $name,
    ?LmsSchool $school,
    ?CourseCategory $category,
    ?int $sortOrder,
    array $settings,
    bool $dryRun,
    User $actor,
  ): LmsProgramModule|false|null {
    $query = LmsProgramModule::query()->where(fn ($q) => $q->where('title', $name)->orWhere('slug', Str::slug($name)));

    if ($school instanceof LmsSchool) {
      $query->where('school_id', $school->id);
    } elseif ($category instanceof CourseCategory) {
      $query->where('category_id', $category->id);
    } else {
      return false;
    }

    $module = $query->first();
    if ($module) {
      return $module;
    }

    if (! $settings['create_missing_program_modules']) {
      return false;
    }

    if ($dryRun) {
      return null;
    }

    if ($school instanceof LmsSchool) {
      return $this->programModules->createForSchool($school, [
        'title' => $name,
        'slug' => Str::slug($name),
        'sort_order' => $sortOrder ?? 1,
      ]);
    }

    if ($category instanceof CourseCategory) {
      return $this->programModules->createForCategory($category, [
        'title' => $name,
        'slug' => Str::slug($name),
        'sort_order' => $sortOrder ?? 1,
      ]);
    }

    return false;
  }

  /**
   * @param  array<string, mixed>  $payload
   * @param  array<string, mixed>  $parsed
   * @return array<string, mixed>
   */
  private function courseSnapshotFromPayload(array $payload, array $parsed): array
  {
    return [
      'title' => $payload['title'],
      'description' => $payload['description'],
      'course_code' => $payload['course_code'] ?? null,
      'is_free' => $payload['is_free'] ?? false,
      'member_price' => $payload['member_price'] ?? null,
      'public_price' => $payload['public_price'] ?? null,
      'youtube_url' => $parsed['youtube_url'] ?? null,
      'duration_minutes' => $parsed['duration_minutes'] ?? null,
    ];
  }

  /**
   * @param  array<string, mixed>  $snapshot
   * @param  array<string, mixed>  $parsed
   */
  private function coursesMatchSnapshot(Course $course, array $snapshot, array $parsed): bool
  {
    if ($course->title !== $snapshot['title']) {
      return false;
    }
    if ((string) $course->description !== (string) ($snapshot['description'] ?? '')) {
      return false;
    }
    if ($parsed['course_code'] !== '' && $course->course_code !== $parsed['course_code']) {
      return false;
    }

    $lesson = $course->modules->flatMap->lessons->first();
    if ($parsed['video_source'] === 'youtube' && $lesson) {
      if ((string) $lesson->youtube_url !== (string) ($snapshot['youtube_url'] ?? '')) {
        return false;
      }
    }

    return true;
  }

  /** @param  array<string, mixed>  $settings */
  private function normalizeSettings(array $settings): array
  {
    return [
      'create_missing_schools' => (bool) ($settings['create_missing_schools'] ?? false),
      'create_missing_categories' => (bool) ($settings['create_missing_categories'] ?? false),
      'create_missing_program_modules' => (bool) ($settings['create_missing_program_modules'] ?? false),
      'publish_after_import' => (bool) ($settings['publish_after_import'] ?? false),
    ];
  }

  /** @return array<string, int> */
  private function emptySummary(int $totalRows): array
  {
    return [
      'total_rows' => $totalRows,
      'valid_rows' => 0,
      'invalid_rows' => 0,
      'duplicate_rows' => 0,
      'existing_courses' => 0,
      'new_courses' => 0,
      'schools_found' => 0,
      'schools_missing' => 0,
      'schools_created' => 0,
      'categories_found' => 0,
      'categories_missing' => 0,
      'categories_created' => 0,
      'program_modules_found' => 0,
      'program_modules_missing' => 0,
      'program_modules_created' => 0,
      'invalid_youtube_urls' => 0,
      'missing_required_fields' => 0,
      'unsupported_values' => 0,
      'imported' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'skipped' => 0,
      'failed' => 0,
    ];
  }

  /** @param  array<string, mixed>  $parsed */
  private function incrementIssueCounters(array &$summary, array $parsed): void
  {
    $issueType = (string) ($parsed['issue_type'] ?? '');
    match ($issueType) {
      'invalid_youtube_urls' => $summary['invalid_youtube_urls']++,
      'unsupported_values' => $summary['unsupported_values']++,
      'missing_required_fields' => $summary['missing_required_fields']++,
      default => null,
    };
  }

  /** @param  array<string, mixed>  $parsed */
  private function trackHierarchyCounts(array &$summary, array $parsed): void
  {
    if ($parsed['access_type'] === 'school') {
      if ($parsed['school'] === false) {
        $summary['schools_missing']++;
      } elseif ($parsed['school'] !== null) {
        $summary['schools_found']++;
      } elseif ($parsed['school'] === null && ($parsed['status'] ?? '') === 'valid') {
        $summary['schools_created']++;
      }
    }

    if ($parsed['access_type'] === 'free') {
      if ($parsed['category'] === false) {
        $summary['categories_missing']++;
      } elseif ($parsed['category'] !== null) {
        $summary['categories_found']++;
      } elseif ($parsed['category'] === null && ($parsed['status'] ?? '') === 'valid') {
        $summary['categories_created']++;
      }
    }

    if ($parsed['program_module_name'] !== '') {
      if ($parsed['program_module'] === false) {
        $summary['program_modules_missing']++;
      } elseif ($parsed['program_module'] !== null) {
        $summary['program_modules_found']++;
      } elseif ($parsed['program_module'] === null && ($parsed['status'] ?? '') === 'valid') {
        $summary['program_modules_created']++;
      }
    }
  }

  /** @param  array<string, mixed>  $parsed */
  private function invalidate(array $parsed, string $issueType, string $message): array
  {
    $parsed['status'] = 'invalid';
    $parsed['issue_type'] = $issueType;
    $parsed['message'] = $message;
    $parsed['issues'][] = $issueType;

    return $parsed;
  }

  /** @param  array<string, mixed>  $parsed */
  private function invalidateHierarchy(array $parsed, string $message): array
  {
    if ($parsed['access_type'] === 'school') {
      $parsed['school'] = false;
    }
    if ($parsed['access_type'] === 'free') {
      $parsed['category'] = false;
    }
    if ($parsed['program_module_name'] !== '') {
      $parsed['program_module'] = false;
    }
    $parsed['status'] = 'invalid_hierarchy';
    $parsed['message'] = $message;
    $parsed['issues'][] = 'unresolved_hierarchy';

    return $parsed;
  }

  /** @param  array<string, mixed>  $parsed */
  private function rowReport(array $parsed): array
  {
    return [
      'row' => $parsed['row'],
      'course_code' => $parsed['course_code'] ?? '',
      'course_title' => $parsed['course_title'] ?? '',
      'access_type' => $parsed['access_type'] ?? '',
      'school_name' => $parsed['school_name'] ?? '',
      'free_category_name' => $parsed['free_category_name'] ?? '',
      'program_module_name' => $parsed['program_module_name'] ?? '',
      'status' => $parsed['status'] ?? 'unknown',
      'action' => $parsed['action'] ?? null,
      'message' => $parsed['message'] ?? '',
      'course_id' => ($parsed['course'] ?? $parsed['existing_course'] ?? null) instanceof Course
        ? ($parsed['course'] ?? $parsed['existing_course'])->uuid
        : null,
    ];
  }

  private function normalizeHeader(string $header): string
  {
    $h = strtolower(trim($header));
    $h = str_replace(['-', ' '], '_', $h);

    return match ($h) {
      'course_title', 'title' => 'course_title',
      'course_description', 'description' => 'course_description',
      'access_type', 'access' => 'access_type',
      'school', 'school_name' => 'school_name',
      'free_category', 'free_category_name', 'category', 'category_name' => 'free_category_name',
      'program_module', 'program_module_name', 'programme_module', 'programme_module_name', 'module_name' => 'program_module_name',
      'module_order', 'program_module_order' => 'module_order',
      'course_order', 'sort_order' => 'course_order',
      'video_source' => 'video_source',
      'youtube', 'youtube_url', 'video_url' => 'youtube_url',
      'video_upload', 'video_upload_path' => 'video_upload_path',
      'duration', 'duration_minutes' => 'duration_minutes',
      'thumbnail_source' => 'thumbnail_source',
      'thumbnail', 'thumbnail_url' => 'thumbnail_url',
      'estimated_duration', 'estimated_completion_minutes' => 'estimated_duration',
      'price_member', 'member_price' => 'price_member',
      'price_non_member', 'public_price', 'non_member_price' => 'price_non_member',
      'currency' => 'currency',
      'certificate_enabled' => 'certificate_enabled',
      'certificate_trigger' => 'certificate_trigger',
      'assessment_enabled' => 'assessment_enabled',
      'assessment_trigger' => 'assessment_trigger',
      'assignment_enabled' => 'assignment_enabled',
      'assignment_trigger' => 'assignment_trigger',
      'resources' => 'resources',
      'status', 'course_status' => 'status',
      default => $h,
    };
  }

  /** @return list<string> */
  private function parseResources(string $raw): array
  {
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $raw) ?: [])));
  }

  private function boolValue(string $raw): bool
  {
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'y'], true);
  }

  private function intOrNull(string $raw): ?int
  {
    $raw = trim($raw);
    if ($raw === '' || ! is_numeric($raw)) {
      return null;
    }

    return max(0, (int) $raw);
  }

  private function decimalOrNull(string $raw): ?float
  {
    $raw = trim($raw);
    if ($raw === '' || ! is_numeric($raw)) {
      return null;
    }

    return round((float) $raw, 2);
  }

  /** @return list<string> */
  private function sampleDataRow(): array
  {
    return [
      'KC-IMPORT-001',
      'Foundations of Marketplace Ministry',
      'Introductory teaching on kingdom influence in the workplace.',
      'free',
      '',
      'Free Learning',
      'Module 1',
      '1',
      '1',
      'youtube',
      'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      '',
      '45',
      'youtube',
      '',
      '45',
      '',
      '',
      'USD',
      'true',
      'course_complete',
      'false',
      '',
      'false',
      '',
      '',
      'draft',
    ];
  }
}
