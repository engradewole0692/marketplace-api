<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseLevel;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Lesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Idempotent import of the Prayer Training timetable spreadsheet.
 *
 * Supports:
 * - Timetable layout: column A = lesson title, column B = YouTube URL, blank rows = module breaks
 * - Tabular layout: optional header row with Week/Module, Lesson #, Title, URL columns
 *
 * Hierarchy: Course → generic Modules → Lessons (YouTube) + optional Exams assessment stub.
 * Ministry/School assignment is intentionally left unassigned for admin review.
 */
final class PrayerTrainingImportService implements ServiceContract
{
  public const COURSE_SLUG = 'prayer-training';

  public const DEFAULT_COURSE_TITLE = 'Prayer Training';

  /** @var list<string> */
  public const DEFAULT_IMPORT_PATHS = [
    'Prayer Training.xlsx',
    'prayer-training.xlsx',
  ];

  /** @var list<string> */
  private const HEADER_ALIASES = [
    'week' => ['week', 'module', 'module #', 'module number', 'timetable'],
    'lesson_number' => ['lesson', 'lesson #', 'lesson number', 'no', 'no.', '#', 'number'],
    'title' => ['title', 'lesson title', 'topic', 'subject', 'name'],
    'url' => ['url', 'youtube', 'youtube url', 'video', 'video url', 'link'],
  ];

  public function __construct(
    private readonly YoutubeMetadataService $youtube,
    private readonly LmsAuditService $auditService,
  ) {}

  /**
   * @return array{
   *   dry_run: bool,
   *   course_slug: string,
   *   stats: array<string, int>,
   *   rows: list<array<string, mixed>>,
   *   notes: list<string>
   * }
   */
  public function importFromPath(string $path, bool $dryRun = true, ?User $actor = null): array
  {
    $resolved = $this->resolveImportPath($path);
    if ($resolved === null) {
      throw new \InvalidArgumentException("Spreadsheet not readable: {$path}");
    }

    $spreadsheet = IOFactory::load($resolved);
    $sheet = $spreadsheet->getActiveSheet();
    $matrix = $sheet->toArray(null, true, true, true);

    return $this->importMatrix($matrix, $dryRun, $actor, basename($resolved));
  }

  public static function resolveDefaultImportPath(): ?string
  {
    foreach (self::DEFAULT_IMPORT_PATHS as $name) {
      $path = database_path('imports/'.$name);
      if (is_readable($path)) {
        return $path;
      }
    }

    return null;
  }

  private function resolveImportPath(string $path): ?string
  {
    if ($path !== '' && is_readable($path)) {
      return $path;
    }

    $candidates = [$path];
    foreach (self::DEFAULT_IMPORT_PATHS as $name) {
      $candidates[] = database_path('imports/'.$name);
    }

    foreach (array_unique($candidates) as $candidate) {
      if ($candidate !== '' && is_readable($candidate)) {
        return $candidate;
      }
    }

    return null;
  }

  /**
   * @return array{
   *   dry_run: bool,
   *   course_slug: string,
   *   stats: array<string, int>,
   *   rows: list<array<string, mixed>>,
   *   notes: list<string>
   * }
   */
  public function importFromUpload(UploadedFile $file, bool $dryRun = true, ?User $actor = null): array
  {
    $spreadsheet = IOFactory::load($file->getRealPath());
    $sheet = $spreadsheet->getActiveSheet();
    $matrix = $sheet->toArray(null, true, true, true);

    return $this->importMatrix($matrix, $dryRun, $actor, $file->getClientOriginalName());
  }

  /**
   * @param  array<string, array<string, mixed>>  $matrix
   * @return array{
   *   dry_run: bool,
   *   course_slug: string,
   *   stats: array<string, int>,
   *   rows: list<array<string, mixed>>,
   *   notes: list<string>
   * }
   */
  private function importMatrix(array $matrix, bool $dryRun, ?User $actor, string $sourceName): array
  {
    $parsed = $this->parseRows($matrix);
    $stats = [
      'course_created' => 0,
      'course_updated' => 0,
      'modules_created' => 0,
      'modules_updated' => 0,
      'lessons_created' => 0,
      'lessons_updated' => 0,
      'lessons_skipped' => 0,
      'assessment_created' => 0,
      'assessment_updated' => 0,
      'rows_failed' => 0,
    ];
    $notes = [];
    $rowReport = [];

    if ($parsed['entries'] === []) {
      return [
        'dry_run' => $dryRun,
        'course_slug' => self::COURSE_SLUG,
        'stats' => $stats,
        'rows' => [],
        'notes' => ['No importable rows found. Check spreadsheet headers and content.'],
      ];
    }

    if ($dryRun) {
      foreach ($parsed['entries'] as $entry) {
        if ($entry['type'] === 'error') {
          $stats['rows_failed']++;
          $rowReport[] = [
            'row' => $entry['row'],
            'type' => 'error',
            'module' => $entry['module_index'] ?? null,
            'lesson_number' => $entry['lesson_number'] ?? null,
            'title' => $entry['title'],
            'youtube_url' => $entry['youtube_url'] ?? null,
            'status' => 'invalid',
            'message' => $entry['error'] ?? 'Invalid row.',
          ];
          continue;
        }

        $rowReport[] = [
          'row' => $entry['row'],
          'type' => $entry['type'],
          'module' => $entry['module_index'],
          'lesson_number' => $entry['lesson_number'] ?? null,
          'title' => $entry['title'],
          'youtube_url' => $entry['youtube_url'] ?? null,
          'status' => 'valid',
          'message' => $entry['type'] === 'assessment'
            ? 'Would ensure assessment placeholder (needs content configuration).'
            : 'Would import video lesson.',
        ];
      }

      $stats['lessons_created'] = count(array_filter($parsed['entries'], fn ($e) => $e['type'] === 'lesson'));
      $stats['assessment_created'] = count(array_filter($parsed['entries'], fn ($e) => $e['type'] === 'assessment'));
      $stats['modules_created'] = count($parsed['modules']);

      $notes[] = sprintf(
        'Dry run: %d modules, %d lessons, %d assessment row(s) from %s.',
        count($parsed['modules']),
        $stats['lessons_created'],
        $stats['assessment_created'],
        $sourceName,
      );

      return [
        'dry_run' => true,
        'course_slug' => self::COURSE_SLUG,
        'stats' => $stats,
        'rows' => $rowReport,
        'notes' => $notes,
      ];
    }

    return DB::transaction(function () use ($parsed, $actor, $sourceName, &$stats, &$notes, &$rowReport): array {
      $course = $this->ensureCourse($actor, $stats, $notes);
      $moduleMap = $this->ensureModules($course, $parsed['modules'], $actor, $stats);

      foreach ($parsed['entries'] as $entry) {
        try {
          if ($entry['type'] === 'error') {
            $stats['rows_failed']++;
            $rowReport[] = [
              'row' => $entry['row'],
              'type' => 'error',
              'title' => $entry['title'] ?? '',
              'status' => 'failed',
              'message' => $entry['error'] ?? 'Invalid row.',
            ];
            continue;
          }

          if ($entry['type'] === 'assessment') {
            $this->ensureAssessmentPlaceholder($course, $entry, $actor, $stats);
            $rowReport[] = [
              'row' => $entry['row'],
              'type' => 'assessment',
              'title' => $entry['title'],
              'status' => 'imported',
              'message' => 'Assessment placeholder ensured (needs content configuration).',
            ];
            continue;
          }

          $module = $moduleMap[$entry['module_index']] ?? null;
          if (! $module) {
            throw new \RuntimeException('Module not found for row '.$entry['row']);
          }

          $lesson = $this->upsertLesson($course, $module, $entry, $actor, $stats);
          $rowReport[] = [
            'row' => $entry['row'],
            'type' => 'lesson',
            'module' => $entry['module_index'],
            'lesson_number' => $entry['lesson_number'],
            'title' => $entry['title'],
            'status' => $lesson->wasRecentlyCreated ? 'created' : 'updated',
            'message' => 'Lesson synchronized.',
          ];
        } catch (\Throwable $e) {
          $stats['rows_failed']++;
          $rowReport[] = [
            'row' => $entry['row'],
            'type' => $entry['type'],
            'title' => $entry['title'] ?? '',
            'status' => 'failed',
            'message' => $e->getMessage(),
          ];
        }
      }

      $this->auditService->record(
        $course,
        $actor,
        'course.imported',
        'Prayer Training spreadsheet import completed.',
        null,
        ['source' => $sourceName, 'stats' => $stats],
      );

      $notes[] = sprintf('Import completed from %s.', $sourceName);

      return [
        'dry_run' => false,
        'course_slug' => self::COURSE_SLUG,
        'stats' => $stats,
        'rows' => $rowReport,
        'notes' => $notes,
      ];
    });
  }

  /**
   * @param  array<string, array<string, mixed>>  $matrix
   * @return array{
   *   entries: list<array{
   *     row: int,
   *     type: 'lesson'|'assessment',
   *     module_index: int,
   *     lesson_number: ?int,
   *     title: string,
   *     youtube_url: ?string,
   *     youtube_video_id: ?string
   *   }>,
   *   modules: list<array{index: int, title: string}>
   * }
   */
  private function parseRows(array $matrix): array
  {
    foreach ($matrix as $rowIndex => $row) {
      $candidate = $this->detectHeaderRow($row);
      if ($candidate !== null) {
        return $this->parseRowsWithHeader($matrix, (int) $rowIndex, $candidate);
      }
    }

    return $this->parseTimetableLayout($matrix);
  }

  /**
   * Prayer Training timetable: column A = title, column B = YouTube URL, blank rows = module breaks.
   *
   * @param  array<string, array<string, mixed>>  $matrix
   * @return array{entries: list<array<string, mixed>>, modules: list<array{index: int, title: string}>}
   */
  private function parseTimetableLayout(array $matrix): array
  {
    $entries = [];
    $modules = [];
    $moduleCounter = 0;
    $lastDataRowIndex = null;

    foreach ($matrix as $rowIndex => $row) {
      $cells = $this->normalizeRow($row);
      if ($this->isBlankRow($cells)) {
        continue;
      }

      $title = trim((string) ($cells['A'] ?? $cells['a'] ?? ''));
      $url = trim((string) ($cells['B'] ?? $cells['b'] ?? ''));

      if ($title === '' && $url !== '') {
        $entries[] = [
          'row' => (int) $rowIndex,
          'type' => 'error',
          'module_index' => max(1, $moduleCounter),
          'lesson_number' => null,
          'title' => '',
          'youtube_url' => $url,
          'youtube_video_id' => null,
          'error' => 'Missing lesson title.',
        ];
        continue;
      }

      if ($title === '') {
        continue;
      }

      if ($lastDataRowIndex !== null && ((int) $rowIndex - $lastDataRowIndex) > 1) {
        $moduleCounter++;
      } elseif ($lastDataRowIndex === null) {
        $moduleCounter = 1;
      }

      if ($moduleCounter === 0) {
        $moduleCounter = 1;
      }

      $modules[$moduleCounter] = [
        'index' => $moduleCounter,
        'title' => 'Module '.$moduleCounter,
      ];

      if ($this->isAssessmentRow($title, $url)) {
        $entries[] = [
          'row' => (int) $rowIndex,
          'type' => 'assessment',
          'module_index' => $moduleCounter,
          'lesson_number' => null,
          'title' => $title,
          'youtube_url' => null,
          'youtube_video_id' => null,
        ];
        $lastDataRowIndex = (int) $rowIndex;
        continue;
      }

      if ($url === '') {
        $entries[] = [
          'row' => (int) $rowIndex,
          'type' => 'error',
          'module_index' => $moduleCounter,
          'lesson_number' => $this->parseLessonNumber('', $title),
          'title' => $title,
          'youtube_url' => null,
          'youtube_video_id' => null,
          'error' => 'Missing YouTube URL.',
        ];
        $lastDataRowIndex = (int) $rowIndex;
        continue;
      }

      $lessonNumber = $this->parseLessonNumber('', $title);
      $youtubeMeta = $this->youtube->resolve($url);

      if (empty($youtubeMeta['youtube_video_id'])) {
        $entries[] = [
          'row' => (int) $rowIndex,
          'type' => 'error',
          'module_index' => $moduleCounter,
          'lesson_number' => $lessonNumber,
          'title' => $title,
          'youtube_url' => $url,
          'youtube_video_id' => null,
          'error' => 'Invalid YouTube URL.',
        ];
        $lastDataRowIndex = (int) $rowIndex;
        continue;
      }

      $entries[] = [
        'row' => (int) $rowIndex,
        'type' => 'lesson',
        'module_index' => $moduleCounter,
        'lesson_number' => $lessonNumber,
        'title' => $title,
        'youtube_url' => $url,
        'youtube_video_id' => $youtubeMeta['youtube_video_id'],
      ];
      $lastDataRowIndex = (int) $rowIndex;
    }

    return [
      'entries' => $entries,
      'modules' => array_values($modules),
    ];
  }

  /**
   * @param  array<string, array<string, mixed>>  $matrix
   * @param  array<string, string>  $columnMap
   * @return array{entries: list<array<string, mixed>>, modules: list<array{index: int, title: string}>}
   */
  private function parseRowsWithHeader(array $matrix, int $headerRow, array $columnMap): array
  {
    $entries = [];
    $modules = [];
    $moduleCounter = 0;
    $lastWeekLabel = null;
    $currentModuleIndex = 0;

    foreach ($matrix as $rowIndex => $row) {
      if ((int) $rowIndex <= $headerRow) {
        continue;
      }

      $cells = $this->normalizeRow($row);
      if ($this->isBlankRow($cells)) {
        continue;
      }

      $weekRaw = $this->cell($cells, $columnMap, 'week');
      $title = trim((string) $this->cell($cells, $columnMap, 'title'));
      $url = trim((string) $this->cell($cells, $columnMap, 'url'));
      $lessonNumberRaw = $this->cell($cells, $columnMap, 'lesson_number');

      if ($title === '' && $weekRaw !== '') {
        $title = trim((string) $weekRaw);
      }

      if ($title === '') {
        continue;
      }

      if ($this->isAssessmentRow($title, $url)) {
        $entries[] = [
          'row' => (int) $rowIndex,
          'type' => 'assessment',
          'module_index' => max(1, $moduleCounter),
          'lesson_number' => null,
          'title' => $title,
          'youtube_url' => null,
          'youtube_video_id' => null,
        ];
        continue;
      }

      if ($weekRaw !== '' && $weekRaw !== $lastWeekLabel) {
        $moduleCounter++;
        $lastWeekLabel = (string) $weekRaw;
        $modules[$moduleCounter] = [
          'index' => $moduleCounter,
          'title' => 'Module '.$moduleCounter,
        ];
        $currentModuleIndex = $moduleCounter;
      }

      if ($currentModuleIndex === 0) {
        $moduleCounter = 1;
        $currentModuleIndex = 1;
        $modules[1] = ['index' => 1, 'title' => 'Module 1'];
      }

      $lessonNumber = $this->parseLessonNumber($lessonNumberRaw, $title);
      $youtubeMeta = $url !== '' ? $this->youtube->resolve($url) : ['youtube_video_id' => null, 'youtube_url' => null];

      if ($url !== '' && empty($youtubeMeta['youtube_video_id'])) {
        throw new \InvalidArgumentException("Invalid YouTube URL on row {$rowIndex}: {$url}");
      }

      $entries[] = [
        'row' => (int) $rowIndex,
        'type' => 'lesson',
        'module_index' => $currentModuleIndex,
        'lesson_number' => $lessonNumber,
        'title' => $title,
        'youtube_url' => $youtubeMeta['youtube_url'] ?? $url,
        'youtube_video_id' => $youtubeMeta['youtube_video_id'] ?? null,
      ];
    }

    return [
      'entries' => $entries,
      'modules' => array_values($modules),
    ];
  }

  /**
   * @param  array<string, mixed>  $row
   * @return array<string, string>|null
   */
  private function detectHeaderRow(array $row): ?array
  {
    $normalized = [];
    foreach ($row as $col => $value) {
      $normalized[(string) $col] = strtolower(trim((string) $value));
    }

    $map = [];
    foreach (self::HEADER_ALIASES as $field => $aliases) {
      foreach ($normalized as $col => $header) {
        if ($header === '') {
          continue;
        }
        foreach ($aliases as $alias) {
          if ($header === $alias || str_contains($header, $alias)) {
            $map[$field] = (string) $col;
            break 2;
          }
        }
      }
    }

    if (! isset($map['title'])) {
      return null;
    }

    if (! isset($map['url'])) {
      $map['url'] = '';
      foreach ($normalized as $col => $header) {
        if (str_contains($header, 'http') || str_contains($header, 'youtu')) {
          $map['url'] = (string) $col;
        }
      }
    }

    return $map;
  }

  /**
   * @param  array<string, mixed>  $cells
   * @param  array<string, string>  $columnMap
   */
  private function cell(array $cells, array $columnMap, string $field): mixed
  {
    $col = $columnMap[$field] ?? '';
    if ($col === '') {
      return '';
    }

    return $cells[$col] ?? '';
  }

  /**
   * @param  array<string, mixed>  $row
   * @return array<string, string>
   */
  private function normalizeRow(array $row): array
  {
    $cells = [];
    foreach ($row as $col => $value) {
      $cells[(string) $col] = is_scalar($value) ? trim((string) $value) : '';
    }

    return $cells;
  }

  /**
   * @param  array<string, string>  $cells
   */
  private function isBlankRow(array $cells): bool
  {
    foreach ($cells as $value) {
      if ($value !== '') {
        return false;
      }
    }

    return true;
  }

  private function isAssessmentRow(string $title, string $url): bool
  {
    if ($url !== '') {
      return false;
    }

    return (bool) preg_match('/\b(exam|exams|examination|assessment|test|quiz)\b/i', $title);
  }

  private function isNumericWeek(mixed $value): bool
  {
    $value = trim((string) $value);

    return $value !== '' && ctype_digit($value);
  }

  private function parseLessonNumber(mixed $raw, string $title): ?int
  {
    if (is_numeric($raw)) {
      return (int) $raw;
    }

    if (preg_match('/lesson\s*#?\s*(\d+)/i', $title, $m)) {
      return (int) $m[1];
    }

    if (preg_match('/^(\d+)\s*[\.\)\-]/', $title, $m)) {
      return (int) $m[1];
    }

    return null;
  }

  private function cleanLessonTitle(string $title, ?int $lessonNumber): string
  {
    $clean = trim(preg_replace('/^lesson\s*#?\s*\d+[\.\:\-\s]*/i', '', $title) ?? $title);
    $clean = trim(preg_replace('/^\d+[\.\)\-\s]+/', '', $clean) ?? $clean);

    return $clean !== '' ? $clean : ($lessonNumber ? 'Lesson '.$lessonNumber : $title);
  }

  /**
   * @param  array<string, int>  $stats
   * @param  list<string>  $notes
   */
  private function ensureCourse(?User $actor, array &$stats, array &$notes): Course
  {
    $categoryId = CourseCategory::query()->where('slug', 'prayer-intercession')->value('id');
    $levelId = CourseLevel::query()->where('slug', 'foundation')->value('id');

    $existing = Course::query()->where('slug', self::COURSE_SLUG)->first();
    $payload = [
      'title' => self::DEFAULT_COURSE_TITLE,
      'summary' => 'Prayer Training video curriculum imported from the official timetable.',
      'description' => 'Structured prayer training with sequential video lessons. Ministry assignment pending admin review.',
      'status' => CourseStatus::Draft,
      'category_id' => $categoryId,
      'level_id' => $levelId,
      'primary_ministry_id' => null,
      'is_free' => true,
      'visitor_free' => true,
      'member_free' => true,
      'public_price' => 0,
      'member_price' => 0,
      'currency' => 'USD',
      'metadata' => [
        'import_source' => 'prayer_training_xlsx',
        'needs_review' => true,
        'ministry_assignment' => 'unassigned',
        'content_review_state' => 'needs_review',
      ],
      'updated_by_user_id' => $actor?->id,
    ];

    if ($existing) {
      $existing->fill($payload)->save();
      $stats['course_updated']++;
      $notes[] = 'Updated existing Prayer Training course.';
    } else {
      $existing = Course::query()->create(array_merge($payload, [
        'slug' => self::COURSE_SLUG,
        'created_by_user_id' => $actor?->id,
      ]));
      $stats['course_created']++;
      $notes[] = 'Created Prayer Training course (draft, ministry unassigned).';
    }

    return $existing->fresh();
  }

  /**
   * @param  list<array{index: int, title: string}>  $modules
   * @param  array<string, int>  $stats
   * @return array<int, CourseModule>
   */
  private function ensureModules(Course $course, array $modules, ?User $actor, array &$stats): array
  {
    $map = [];

    foreach ($modules as $moduleDef) {
      $index = (int) $moduleDef['index'];
      $slug = 'module-'.$index;
      $existing = CourseModule::query()
        ->where('course_id', $course->id)
        ->where('slug', $slug)
        ->first();

      $payload = [
        'title' => $moduleDef['title'],
        'sort_order' => $index,
        'status' => ModuleStatus::Published,
        'updated_by_user_id' => $actor?->id,
      ];

      if ($existing) {
        $existing->fill($payload)->save();
        $stats['modules_updated']++;
      } else {
        $existing = CourseModule::query()->create(array_merge($payload, [
          'course_id' => $course->id,
          'slug' => $slug,
          'created_by_user_id' => $actor?->id,
        ]));
        $stats['modules_created']++;
      }

      $map[$index] = $existing;
    }

    return $map;
  }

  /**
   * @param  array<string, mixed>  $entry
   * @param  array<string, int>  $stats
   */
  private function upsertLesson(Course $course, CourseModule $module, array $entry, ?User $actor, array &$stats): Lesson
  {
    $slug = $this->lessonSlug($entry['lesson_number'], $entry['title']);

    $existing = Lesson::query()
      ->where('course_id', $course->id)
      ->where('slug', $slug)
      ->first();

    $payload = [
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => $entry['title'],
      'slug' => $slug,
      'sort_order' => $entry['lesson_number'] ?? ($existing?->sort_order ?? (($module->lessons()->max('sort_order') ?? 0) + 1)),
      'status' => ModuleStatus::Published,
      'lesson_type' => LessonType::Video,
      'video_source' => VideoSource::Youtube,
      'youtube_url' => $entry['youtube_url'],
      'youtube_video_id' => $entry['youtube_video_id'],
      'is_mandatory' => true,
      'updated_by_user_id' => $actor?->id,
    ];

    if ($existing) {
      $existing->fill($payload)->save();
      $stats['lessons_updated']++;

      return $existing;
    }

    $lesson = Lesson::query()->create(array_merge($payload, [
      'created_by_user_id' => $actor?->id,
    ]));
    $stats['lessons_created']++;

    return $lesson;
  }

  /**
   * @param  array<string, mixed>  $entry
   * @param  array<string, int>  $stats
   */
  private function ensureAssessmentPlaceholder(Course $course, array $entry, ?User $actor, array &$stats): Assessment
  {
    $slug = 'prayer-training-final-exam';
    $existing = Assessment::query()
      ->where('course_id', $course->id)
      ->where('slug', $slug)
      ->first();

    $payload = [
      'course_id' => $course->id,
      'title' => $entry['title'] !== '' ? $entry['title'] : 'Final Examination',
      'description' => 'Examination content must be configured by an administrator. Imported from spreadsheet without fabricated questions.',
      'assessment_type' => AssessmentType::Examination,
      'status' => AssessmentStatus::Draft,
      'pass_mark' => 70,
      'max_attempts' => 3,
      'requires_instructor_grading' => false,
      'settings' => [
        'needs_content_configuration' => true,
        'import_source' => 'prayer_training_xlsx',
      ],
      'updated_by_user_id' => $actor?->id,
    ];

    if ($existing) {
      $existing->fill($payload)->save();
      $stats['assessment_updated']++;

      return $existing;
    }

    $assessment = Assessment::query()->create(array_merge($payload, [
      'slug' => $slug,
      'created_by_user_id' => $actor?->id,
    ]));
    $stats['assessment_created']++;

    return $assessment;
  }

  private function lessonSlug(?int $lessonNumber, string $title): string
  {
    if ($lessonNumber !== null) {
      return 'lesson-'.$lessonNumber;
    }

    $base = Str::slug($title) ?: 'lesson';

    return Str::limit($base, 200, '');
  }
}
