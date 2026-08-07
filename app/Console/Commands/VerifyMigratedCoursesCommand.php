<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Lms\Services\CourseMigrationVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class VerifyMigratedCoursesCommand extends Command
{
  protected $signature = 'lms:verify-migrated-courses
                            {--report= : Optional path to write JSON verification report}';

  protected $description = 'Verify every migrated course for video playback, downloads, enrollment, certificates, and assessments.';

  public function handle(CourseMigrationVerificationService $verification): int
  {
    $result = $verification->verify();

    foreach ($result['courses'] as $course) {
      $mark = $course['passed'] ? 'PASS' : 'FAIL';
      $this->line("[{$mark}] {$course['slug']}".(isset($course['title']) ? " — {$course['title']}" : ''));
      foreach ($course['checks'] as $name => $ok) {
        $this->line('    '.($ok ? '✓' : '✗').' '.$name);
      }
      foreach ($course['details'] as $detail) {
        $this->line('      · '.$detail);
      }
    }

    $this->newLine();
    $this->info(sprintf(
      '%d/%d passed',
      $result['summary']['passed'],
      $result['summary']['total'],
    ));

    $path = $this->option('report');
    if (! is_string($path) || $path === '') {
      $path = storage_path('app/lms/m6g-verification-report.json');
    }
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
      'verified_at' => now()->toIso8601String(),
      'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->line('Report: '.$path);

    return $result['passed'] ? self::SUCCESS : self::FAILURE;
  }
}
