<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    // Remap legacy statuses to the enterprise workflow.
    DB::table('cms_form_submissions')->where('status', 'in_progress')->update(['status' => 'processing']);
    DB::table('cms_form_submissions')->where('status', 'processed')->update(['status' => 'completed']);
    DB::table('cms_form_submissions')->where('status', 'archived')->update(['status' => 'closed']);

    Schema::table('cms_form_submissions', function (Blueprint $table): void {
      $table->string('submitter_phone')->nullable()->after('submitter_email');
      $table->timestamp('email_notified_at')->nullable()->after('processed_by');
      $table->timestamp('sms_notified_at')->nullable()->after('email_notified_at');
      $table->timestamp('whatsapp_notified_at')->nullable()->after('sms_notified_at');
    });

    Schema::create('cms_form_submission_events', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('submission_id')->constrained('cms_form_submissions')->cascadeOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('event_type');
      $table->string('title');
      $table->text('body')->nullable();
      $table->json('meta')->nullable();
      $table->timestamps();

      $table->index(['submission_id', 'created_at']);
    });

    Schema::create('cms_form_submission_attachments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('submission_id')->constrained('cms_form_submissions')->cascadeOnDelete();
      $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
      $table->string('disk')->default('public');
      $table->string('path');
      $table->string('file_name');
      $table->string('mime_type')->nullable();
      $table->unsignedBigInteger('size')->default(0);
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('cms_form_submission_attachments');
    Schema::dropIfExists('cms_form_submission_events');

    Schema::table('cms_form_submissions', function (Blueprint $table): void {
      $table->dropColumn(['submitter_phone', 'email_notified_at', 'sms_notified_at', 'whatsapp_notified_at']);
    });

    DB::table('cms_form_submissions')->where('status', 'processing')->update(['status' => 'in_progress']);
    DB::table('cms_form_submissions')->where('status', 'completed')->update(['status' => 'processed']);
    DB::table('cms_form_submissions')->where('status', 'closed')->update(['status' => 'archived']);
  }
};
