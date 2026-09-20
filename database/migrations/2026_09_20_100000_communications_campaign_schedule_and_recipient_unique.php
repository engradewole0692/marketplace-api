<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_email_jobs') && ! Schema::hasColumn('bulk_email_jobs', 'scheduled_at')) {
            Schema::table('bulk_email_jobs', function (Blueprint $table): void {
                $table->timestamp('scheduled_at')->nullable()->after('queued_at');
            });
        }

        if (Schema::hasTable('bulk_email_recipients')) {
            try {
                Schema::table('bulk_email_recipients', function (Blueprint $table): void {
                    $table->unique(['bulk_email_job_id', 'email'], 'bulk_email_recipients_job_email_unique');
                });
            } catch (\Throwable) {
                // Unique index may already exist in some environments.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bulk_email_jobs') && Schema::hasColumn('bulk_email_jobs', 'scheduled_at')) {
            Schema::table('bulk_email_jobs', function (Blueprint $table): void {
                $table->dropColumn('scheduled_at');
            });
        }
        if (Schema::hasTable('bulk_email_recipients')) {
            Schema::table('bulk_email_recipients', function (Blueprint $table): void {
                $table->dropUnique('bulk_email_recipients_job_email_unique');
            });
        }
    }
};
