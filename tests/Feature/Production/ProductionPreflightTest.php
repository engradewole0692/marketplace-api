<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Services\Production\ProductionPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ProductionPreflightTest extends TestCase
{
  use RefreshDatabase;

  public function test_preflight_command_runs_and_reports_pass_for_test_environment(): void
  {
    Artisan::call('production:preflight');
    $output = Artisan::output();

    $this->assertStringContainsString('PASS', $output);
    $this->assertStringContainsString('Database', $output);
    $this->assertStringContainsString('events:send-reminders', $output);
  }

  public function test_preflight_service_includes_required_table_checks(): void
  {
    $checks = app(ProductionPreflightService::class)->run();
    $names = array_column($checks, 'check');

    $this->assertContains('Required tables', $names);
    $this->assertContains('APP_KEY', $names);
    $this->assertContains('MAIL_MAILER', $names);

    $tables = collect($checks)->firstWhere('check', 'Required tables');
    $this->assertSame('PASS', $tables['status'] ?? null);
  }

  public function test_preflight_json_output_does_not_contain_passwords(): void
  {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
    putenv('MAIL_PASSWORD=super-secret-value');
    $_ENV['MAIL_PASSWORD'] = 'super-secret-value';

    Artisan::call('production:preflight', ['--json' => true]);
    $output = Artisan::output();

    $this->assertStringNotContainsString('super-secret-value', $output);
    $this->assertStringContainsString('MAIL_PASSWORD', $output);
  }
}
