<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Lms\Services\LmsCourseImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ImportFreeCoursesCommand extends Command
{
  protected $signature = 'lms:import-free-courses
                            {path? : Path to the Kingdom Collective LMS import workbook}
                            {--dry-run : Preview without writing}
                            {--publish : Publish imported free courses}';

  protected $description = 'Import only access_type=free rows into existing free categories. Paid school rows are ignored.';

  public function handle(LmsCourseImportService $import): int
  {
    $path = (string) ($this->argument('path') ?: $this->defaultPath());
    $dryRun = (bool) $this->option('dry-run');

    if ($path === '' || ! is_readable($path)) {
      $this->error('Workbook not found or unreadable.');
      if ($path !== '') {
        $this->line("Attempted path: {$path}");
      }
      $this->line('Pass the Kingdom Collective LMS Course Import Template, or place it at database/imports/Kingdom_Collective_LMS_Course_Import_Template.xlsx');

      return self::FAILURE;
    }

    $actor = User::query()->orderBy('id')->first();
    if ($actor === null) {
      $this->error('No user exists to attribute this import.');

      return self::FAILURE;
    }

    $this->info($dryRun ? 'Free-course import dry run…' : 'Importing free courses only…');
    $this->line('Source: '.$path);
    $this->line('Paid school rows will be skipped. Incomplete access_type rows will be reported, not guessed.');

    try {
      $result = $import->importFromPath($path, [
        'create_missing_schools' => false,
        'create_missing_categories' => true,
        'create_missing_program_modules' => true,
        'publish_after_import' => (bool) $this->option('publish'),
        'only_access_types' => ['free'],
      ], $dryRun, $actor);
    } catch (\Throwable $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    }

    $summary = $result['summary'] ?? [];
    $this->table(
      ['Metric', 'Count'],
      collect($summary)->map(fn ($value, $key) => [$key, $value])->values()->all(),
    );

    $unresolved = collect($result['rows'] ?? [])
      ->filter(fn (array $row) => in_array($row['status'] ?? '', ['invalid', 'invalid_hierarchy'], true))
      ->values();

    if ($unresolved->isNotEmpty()) {
      $this->newLine();
      $this->warn('Unresolved rows (not guessed):');
      $this->table(
        ['Row', 'Title', 'Access', 'Category', 'Message'],
        $unresolved->map(fn (array $row) => [
          $row['row'] ?? '',
          Str::limit((string) ($row['course_title'] ?? ''), 40),
          $row['access_type'] ?? '',
          $row['free_category_name'] ?? '',
          Str::limit((string) ($row['message'] ?? ''), 60),
        ])->all(),
      );
    }

    $this->info('Done. Paid school curriculum was not modified.');

    return ($summary['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
  }

  private function defaultPath(): string
  {
    $candidates = [
      database_path('imports/Kingdom_Collective_LMS_Course_Import_Template.xlsx'),
      database_path('imports/Kingdom_Collective_LMS_Course_Import_Template (3).xlsx'),
    ];

    foreach ($candidates as $candidate) {
      if (is_readable($candidate)) {
        return $candidate;
      }
    }

    return $candidates[0];
  }
}
