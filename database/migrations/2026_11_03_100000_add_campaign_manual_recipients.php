<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manually supplied campaign email addresses and per-recipient source tracking.
 * Additive only: no existing rows are changed or removed.
 *
 * Every index and foreign key is explicitly named to stay under MySQL's 64-character
 * identifier limit. Laravel's default foreign key name here would be
 * communication_campaign_manual_recipients_bulk_email_job_id_foreign (66).
 *
 * MySQL DDL is not transactional: a failed run can leave the table created without its
 * unique index or foreign key. Re-running up() adds only the missing pieces.
 */
return new class extends Migration
{
  private const TABLE = 'communication_campaign_manual_recipients';

  private const JOB_FK = 'ccmr_job_fk';

  private const JOB_EMAIL_UNIQUE = 'ccmr_job_email_uq';

  private const LEGACY_JOB_EMAIL_UNIQUE = 'campaign_manual_recipients_job_email_unique';

  private const RECIPIENTS_TABLE = 'bulk_email_recipients';

  private const SOURCE_INDEX = 'ber_job_source_idx';

  private const LEGACY_SOURCE_INDEX = 'bulk_email_recipients_job_source_index';

  public function up(): void
  {
    $this->createOrRepairManualRecipients();
    $this->addRecipientSource();
  }

  public function down(): void
  {
    if (Schema::hasTable(self::RECIPIENTS_TABLE) && Schema::hasColumn(self::RECIPIENTS_TABLE, 'source')) {
      foreach ([self::SOURCE_INDEX, self::LEGACY_SOURCE_INDEX] as $index) {
        if (Schema::hasIndex(self::RECIPIENTS_TABLE, $index)) {
          Schema::table(self::RECIPIENTS_TABLE, fn (Blueprint $table) => $table->dropIndex($index));
        }
      }
      Schema::table(self::RECIPIENTS_TABLE, fn (Blueprint $table) => $table->dropColumn('source'));
    }

    Schema::dropIfExists(self::TABLE);
  }

  private function createOrRepairManualRecipients(): void
  {
    if (! Schema::hasTable(self::TABLE)) {
      Schema::create(self::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('bulk_email_job_id');
        $table->string('email', 254);
        $table->timestamps();

        // Unique first so MySQL reuses it for the foreign key instead of adding an implicit index.
        $table->unique(['bulk_email_job_id', 'email'], self::JOB_EMAIL_UNIQUE);
        $table->foreign('bulk_email_job_id', self::JOB_FK)
          ->references('id')->on('bulk_email_jobs')->cascadeOnDelete();
      });

      return;
    }

    if (! $this->hasJobEmailUnique()) {
      $duplicates = DB::table(self::TABLE)
        ->select(['bulk_email_job_id', 'email'])
        ->groupBy('bulk_email_job_id', 'email')
        ->havingRaw('COUNT(*) > 1')
        ->limit(1)
        ->exists();
      if ($duplicates) {
        throw new RuntimeException(
          'Cannot add '.self::JOB_EMAIL_UNIQUE.': '.self::TABLE.' contains duplicate (bulk_email_job_id, email) rows. '
          .'Resolve the duplicates manually, then re-run the migration.',
        );
      }

      Schema::table(self::TABLE, fn (Blueprint $table) => $table->unique(['bulk_email_job_id', 'email'], self::JOB_EMAIL_UNIQUE));
    }

    if (! $this->hasJobForeignKey()) {
      $orphans = DB::table(self::TABLE.' as r')
        ->leftJoin('bulk_email_jobs as j', 'j.id', '=', 'r.bulk_email_job_id')
        ->whereNull('j.id')
        ->exists();
      if ($orphans) {
        throw new RuntimeException(
          'Cannot add '.self::JOB_FK.': '.self::TABLE.' references bulk_email_jobs rows that no longer exist. '
          .'Resolve the orphaned rows manually, then re-run the migration.',
        );
      }

      Schema::table(self::TABLE, function (Blueprint $table): void {
        $table->foreign('bulk_email_job_id', self::JOB_FK)
          ->references('id')->on('bulk_email_jobs')->cascadeOnDelete();
      });
    }
  }

  private function addRecipientSource(): void
  {
    if (! Schema::hasTable(self::RECIPIENTS_TABLE)) {
      return;
    }

    if (! Schema::hasColumn(self::RECIPIENTS_TABLE, 'source')) {
      $afterPerson = Schema::hasColumn(self::RECIPIENTS_TABLE, 'person_id');
      Schema::table(self::RECIPIENTS_TABLE, function (Blueprint $table) use ($afterPerson): void {
        $column = $table->string('source', 16)->nullable();
        if ($afterPerson) {
          $column->after('person_id');
        }
      });
    }

    if (! Schema::hasIndex(self::RECIPIENTS_TABLE, self::SOURCE_INDEX)
      && ! Schema::hasIndex(self::RECIPIENTS_TABLE, self::LEGACY_SOURCE_INDEX)) {
      Schema::table(self::RECIPIENTS_TABLE, fn (Blueprint $table) => $table->index(['bulk_email_job_id', 'source'], self::SOURCE_INDEX));
    }
  }

  private function hasJobEmailUnique(): bool
  {
    return Schema::hasIndex(self::TABLE, self::JOB_EMAIL_UNIQUE)
      || Schema::hasIndex(self::TABLE, self::LEGACY_JOB_EMAIL_UNIQUE)
      || Schema::hasIndex(self::TABLE, ['bulk_email_job_id', 'email'], 'unique');
  }

  private function hasJobForeignKey(): bool
  {
    foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
      if (($foreignKey['columns'] ?? []) === ['bulk_email_job_id'] && ($foreignKey['foreign_table'] ?? null) === 'bulk_email_jobs') {
        return true;
      }
    }

    return false;
  }
};
