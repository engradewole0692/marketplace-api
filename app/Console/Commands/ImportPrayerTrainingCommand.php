<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Lms\Services\PrayerTrainingImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ImportPrayerTrainingCommand extends Command
{
  protected $signature = 'lms:import-prayer-training
                            {path? : Path to the Prayer Training spreadsheet (defaults to database/imports/Prayer Training.xlsx or prayer-training.xlsx)}
                            {--dry-run : Preview without writing}';

  protected $description = 'Import Prayer Training course modules, YouTube lessons, and exam placeholder from the official timetable spreadsheet.';

  public function handle(PrayerTrainingImportService $import): int
  {
    $path = (string) ($this->argument('path') ?: (PrayerTrainingImportService::resolveDefaultImportPath() ?? ''));
    $dryRun = (bool) $this->option('dry-run');

    if ($path === '' || ! is_readable($path)) {
      $this->error('Spreadsheet not found or unreadable.');
      if ($path !== '') {
        $this->line("Attempted path: {$path}");
      }
      $this->line('Place the spreadsheet at database/imports/Prayer Training.xlsx (or prayer-training.xlsx) or pass an explicit path.');
      $this->line('Example: php artisan lms:import-prayer-training database/imports/"Prayer Training.xlsx" --dry-run');

      return self::FAILURE;
    }

    $this->info($dryRun ? 'Prayer Training import dry run…' : 'Importing Prayer Training…');
    $this->line('Source: '.$path);

    try {
      $result = $import->importFromPath($path, $dryRun);
    } catch (\Throwable $e) {
      $this->error($e->getMessage());

      return self::FAILURE;
    }

    $this->table(['Metric', 'Count'], collect($result['stats'])->map(fn ($v, $k) => [$k, $v])->values()->all());

    foreach ($result['notes'] as $note) {
      $this->line('• '.$note);
    }

    if ($result['rows'] !== []) {
      $this->newLine();
      $this->info('Row preview:');
      $this->table(
        ['Row', 'Type', 'Module', 'Lesson #', 'Title', 'YouTube URL', 'Status', 'Message'],
        collect($result['rows'])->map(fn ($row) => [
          $row['row'] ?? '',
          $row['type'] ?? '',
          $row['module'] ?? '',
          $row['lesson_number'] ?? '',
          Str::limit((string) ($row['title'] ?? ''), 50),
          Str::limit((string) ($row['youtube_url'] ?? ''), 40),
          $row['status'] ?? '',
          Str::limit((string) ($row['message'] ?? ''), 40),
        ])->all(),
      );
    }

    if (($result['stats']['rows_failed'] ?? 0) > 0) {
      $this->warn('Some rows failed — review the import report above.');

      return self::FAILURE;
    }

    $this->info('Done.');

    return self::SUCCESS;
  }
}
