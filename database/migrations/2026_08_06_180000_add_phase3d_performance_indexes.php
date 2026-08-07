<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    $this->addIndexIfMissing('cms_audit_logs', 'created_at', 'cms_audit_logs_created_at_index');
    $this->addIndexIfMissing('iam_audit_logs', 'created_at', 'iam_audit_logs_created_at_index');
    $this->addIndexIfMissing('member_audit_logs', 'created_at', 'member_audit_logs_created_at_index');
    $this->addIndexIfMissing('members', 'activated_at', 'members_activated_at_index');
    $this->addCompositeIndexIfMissing(
      'member_notification_queue',
      ['member_id', 'created_at'],
      'member_notification_queue_member_created_index',
    );
    $this->addIndexIfMissing('cms_form_submissions', 'submitter_email', 'cms_form_submissions_submitter_email_index');
    $this->addCompositeIndexIfMissing(
      'lms_enrollments',
      ['member_id', 'status'],
      'lms_enrollments_member_status_index',
    );
  }

  public function down(): void
  {
    $this->dropIndexIfExists('cms_audit_logs', 'cms_audit_logs_created_at_index');
    $this->dropIndexIfExists('iam_audit_logs', 'iam_audit_logs_created_at_index');
    $this->dropIndexIfExists('member_audit_logs', 'member_audit_logs_created_at_index');
    $this->dropIndexIfExists('members', 'members_activated_at_index');
    $this->dropIndexIfExists('member_notification_queue', 'member_notification_queue_member_created_index');
    $this->dropIndexIfExists('cms_form_submissions', 'cms_form_submissions_submitter_email_index');
    $this->dropIndexIfExists('lms_enrollments', 'lms_enrollments_member_status_index');
  }

  private function addIndexIfMissing(string $table, string $column, string $indexName): void
  {
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
      return;
    }

    if ($this->indexExists($table, $indexName)) {
      return;
    }

    Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
      $blueprint->index($column, $indexName);
    });
  }

  /**
   * @param  list<string>  $columns
   */
  private function addCompositeIndexIfMissing(string $table, array $columns, string $indexName): void
  {
    if (! Schema::hasTable($table)) {
      return;
    }

    foreach ($columns as $column) {
      if (! Schema::hasColumn($table, $column)) {
        return;
      }
    }

    if ($this->indexExists($table, $indexName)) {
      return;
    }

    Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
      $blueprint->index($columns, $indexName);
    });
  }

  private function dropIndexIfExists(string $table, string $indexName): void
  {
    if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
      return;
    }

    Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
      $blueprint->dropIndex($indexName);
    });
  }

  private function indexExists(string $table, string $indexName): bool
  {
    try {
      $connection = Schema::getConnection();
      $database = $connection->getDatabaseName();
      $driver = $connection->getDriverName();

      if ($driver === 'sqlite') {
        $rows = $connection->select("PRAGMA index_list('{$table}')");
        foreach ($rows as $row) {
          $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
          if ($name === $indexName) {
            return true;
          }
        }

        return false;
      }

      if ($driver === 'pgsql') {
        $rows = $connection->select(
          'select 1 from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
          [$table, $indexName],
        );

        return count($rows) > 0;
      }

      $rows = $connection->select(
        'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
        [$database, $table, $indexName],
      );

      return count($rows) > 0;
    } catch (\Throwable) {
      return false;
    }
  }
};
