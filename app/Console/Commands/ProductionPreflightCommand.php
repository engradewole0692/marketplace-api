<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Production\ProductionPreflightService;
use Illuminate\Console\Command;

final class ProductionPreflightCommand extends Command
{
  protected $signature = 'production:preflight {--json : Output results as JSON}';

  protected $description = 'Read-only production readiness checks (PASS/WARN/FAIL). Does not modify data.';

  public function handle(ProductionPreflightService $preflight): int
  {
    $checks = $preflight->run();
    $summary = $preflight->summarize($checks);

    if ($this->option('json')) {
      $this->line(json_encode(['summary' => $summary, 'checks' => $checks], JSON_PRETTY_PRINT));

      return $summary['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    $this->info('Production Preflight — read-only checks');
    $this->newLine();

    $rows = array_map(
      static fn (array $check): array => [$check['category'], $check['check'], $check['status'], $check['message']],
      $checks,
    );

    $this->table(['Category', 'Check', 'Status', 'Message'], $rows);

    $this->newLine();
    $this->line("Summary: {$summary['pass']} PASS, {$summary['warn']} WARN, {$summary['fail']} FAIL");

    return $summary['fail'] > 0 ? self::FAILURE : self::SUCCESS;
  }
}
