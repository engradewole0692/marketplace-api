<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Lms\Services\CourseMigrationService;
use App\Modules\Lms\Services\CourseMigrationVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MigrateLegacyCoursesCommand extends Command
{
  protected $signature = 'lms:migrate-legacy-courses
                            {--dry-run : Preview without writing}
                            {--verify : Run post-migration verification}
                            {--report= : Optional path to write JSON migration report}';

  protected $description = 'Import existing YouTube library, videos, PDFs, resources, and instructors into LMS without rebuilding existing courses or duplicating media.';

  public function handle(
    CourseMigrationService $migration,
    CourseMigrationVerificationService $verification,
  ): int {
    $dryRun = (bool) $this->option('dry-run');
    $this->info($dryRun ? 'M6-G dry run…' : 'M6-G migrating legacy courses…');

    $result = $migration->migrate($dryRun);

    $this->table(
      ['Metric', 'Count'],
      collect($result['stats'])->map(fn ($v, $k) => [$k, $v])->values()->all(),
    );

    foreach ($result['notes'] as $note) {
      $this->line('• '.$note);
    }

    if ((int) ($result['stats']['media_uploads'] ?? 0) !== 0) {
      $this->error('Abort: media uploads must remain zero (reuse Media Library only).');

      return self::FAILURE;
    }

    $payload = [
      'migrated_at' => now()->toIso8601String(),
      'dry_run' => $dryRun,
      'result' => $result,
    ];

    if ($this->option('verify') && ! $dryRun) {
      $verify = $verification->verify();
      $payload['verification'] = $verify;
      $this->newLine();
      $this->info(sprintf(
        'Verification: %d/%d courses passed',
        $verify['summary']['passed'],
        $verify['summary']['total'],
      ));
      foreach ($verify['courses'] as $course) {
        $mark = $course['passed'] ? 'PASS' : 'FAIL';
        $this->line("[{$mark}] {$course['slug']}");
      }
      if (! $verify['passed']) {
        $this->writeReport($payload);

        return self::FAILURE;
      }
    }

    $this->writeReport($payload);
    $this->info('Done.');

    return self::SUCCESS;
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function writeReport(array $payload): void
  {
    $path = $this->option('report');
    if (! is_string($path) || $path === '') {
      $path = storage_path('app/lms/m6g-migration-report.json');
    }

    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->line('Report: '.$path);
  }
}
