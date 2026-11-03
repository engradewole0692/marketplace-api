<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manually supplied campaign email addresses and per-recipient source tracking.
 * Additive only: no existing rows are changed.
 */
return new class extends Migration
{
  public function up(): void
  {
    if (! Schema::hasTable('communication_campaign_manual_recipients')) {
      Schema::create('communication_campaign_manual_recipients', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('bulk_email_job_id')->constrained('bulk_email_jobs')->cascadeOnDelete();
        $table->string('email', 254);
        $table->timestamps();

        $table->unique(['bulk_email_job_id', 'email'], 'campaign_manual_recipients_job_email_unique');
      });
    }

    if (! Schema::hasColumn('bulk_email_recipients', 'source')) {
      Schema::table('bulk_email_recipients', function (Blueprint $table): void {
        $table->string('source', 16)->nullable()->after('person_id');
        $table->index(['bulk_email_job_id', 'source'], 'bulk_email_recipients_job_source_index');
      });
    }
  }

  public function down(): void
  {
    if (Schema::hasColumn('bulk_email_recipients', 'source')) {
      Schema::table('bulk_email_recipients', function (Blueprint $table): void {
        $table->dropIndex('bulk_email_recipients_job_source_index');
        $table->dropColumn('source');
      });
    }

    Schema::dropIfExists('communication_campaign_manual_recipients');
  }
};
