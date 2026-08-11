<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies migrations use Laravel schema builder patterns compatible with MySQL 8.x.
 * Does not require a live MySQL connection.
 */
final class MysqlMigrationCompatibilityTest extends TestCase
{
  use RefreshDatabase;

  /** @var list<string> */
  private const REQUIRED_TABLES = [
    'communication_settings',
    'communication_routes',
    'communication_templates',
    'communication_email_logs',
    'communication_idempotency_keys',
    'lms_program_modules',
    'lms_courses',
    'lms_schools',
    'lms_school_orders',
    'lms_course_orders',
    'lms_school_enrollments',
  ];

  public function test_required_lms_and_communications_tables_exist_after_migrate(): void
  {
    foreach (self::REQUIRED_TABLES as $table) {
      $this->assertTrue(
        Schema::hasTable($table),
        "Expected table [{$table}] after migrations.",
      );
    }
  }

  public function test_lms_courses_has_program_module_foreign_key_column(): void
  {
    $this->assertTrue(Schema::hasColumn('lms_courses', 'program_module_id'));
  }

  public function test_migration_files_avoid_sqlite_only_raw_sql(): void
  {
    $files = File::glob(database_path('migrations/*.php')) ?: [];
    $this->assertNotEmpty($files);

    $violations = [];
    foreach ($files as $file) {
      $contents = File::get($file);
      $basename = basename($file);

      if (preg_match('/DB::statement\s*\(\s*[\'"]\s*(CREATE|ALTER|DROP)/i', $contents)) {
        $violations[] = "{$basename}: raw DDL via DB::statement";
      }

      if (str_contains($contents, 'AUTOINCREMENT') && ! str_contains($contents, 'sqlite')) {
        $violations[] = "{$basename}: SQLite AUTOINCREMENT syntax outside guarded block";
      }
    }

    $this->assertSame([], $violations, 'Migration compatibility issues: '.implode('; ', $violations));
  }
}
