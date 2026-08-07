<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Enterprise Counselling Management System (additive).
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::create('counselling_categories', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->text('description')->nullable();
      $table->string('icon', 80)->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('is_visible')->default(true);
      $table->string('status', 32)->default('active');
      $table->string('seo_title')->nullable();
      $table->text('seo_description')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_services', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('category_id')->nullable()->constrained('counselling_categories')->nullOnDelete();
      $table->string('title');
      $table->string('slug')->unique();
      $table->text('description')->nullable();
      $table->text('short_description')->nullable();
      $table->string('icon', 80)->nullable();
      $table->foreignId('banner_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->unsignedInteger('duration_minutes')->default(60);
      $table->string('format', 32)->default('hybrid'); // physical|virtual|hybrid
      $table->string('google_meet_link', 500)->nullable();
      $table->string('zoom_link', 500)->nullable();
      $table->string('teams_link', 500)->nullable();
      $table->string('office_address')->nullable();
      $table->unsignedInteger('maximum_sessions')->default(1);
      $table->boolean('requires_approval')->default(true);
      $table->boolean('requires_payment')->default(false);
      $table->boolean('is_free')->default(true);
      $table->decimal('visitor_price', 12, 2)->nullable();
      $table->decimal('member_price', 12, 2)->nullable();
      $table->string('currency', 3)->default('USD');
      $table->boolean('is_visible')->default(true);
      $table->boolean('is_featured')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('seo_title')->nullable();
      $table->text('seo_description')->nullable();
      $table->string('status', 32)->default('published');
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_counsellors', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->string('display_name');
      $table->string('slug')->unique();
      $table->text('biography')->nullable();
      $table->json('specializations')->nullable();
      $table->json('languages')->nullable();
      $table->foreignId('photo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('google_meet_link', 500)->nullable();
      $table->string('zoom_link', 500)->nullable();
      $table->string('teams_link', 500)->nullable();
      $table->unsignedInteger('max_daily_sessions')->default(6);
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_counsellor_availability', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('counsellor_id')->constrained('counselling_counsellors')->cascadeOnDelete();
      $table->unsignedTinyInteger('weekday'); // 0=Sun .. 6=Sat
      $table->time('starts_at');
      $table->time('ends_at');
      $table->string('timezone', 64)->default('UTC');
      $table->boolean('is_active')->default(true);
      $table->timestamps();
      $table->unique(['counsellor_id', 'weekday', 'starts_at', 'ends_at'], 'counselling_avail_unique');
    });

    Schema::create('counselling_cases', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('case_number')->unique();
      $table->foreignId('service_id')->constrained('counselling_services')->restrictOnDelete();
      $table->foreignId('category_id')->nullable()->constrained('counselling_categories')->nullOnDelete();
      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->foreignId('counsellor_id')->nullable()->constrained('counselling_counsellors')->nullOnDelete();
      $table->foreignId('source_submission_id')->nullable()->constrained('cms_form_submissions')->nullOnDelete();
      $table->string('client_type', 32)->default('visitor'); // visitor|member
      $table->string('status', 40)->default('pending');
      $table->string('preferred_format', 32)->nullable();
      $table->string('client_name');
      $table->string('client_email');
      $table->string('client_phone')->nullable();
      $table->string('client_country')->nullable();
      $table->string('client_gender')->nullable();
      $table->string('preferred_counsellor_gender')->nullable();
      $table->text('reason')->nullable();
      $table->text('prayer_request')->nullable();
      $table->timestamp('preferred_at')->nullable();
      $table->string('timezone', 64)->default('UTC');
      $table->unsignedInteger('session_count')->default(0);
      $table->boolean('allow_reschedule')->default(true);
      $table->boolean('allow_cancel')->default(true);
      $table->timestamp('assigned_at')->nullable();
      $table->timestamp('scheduled_at')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->text('cancellation_reason')->nullable();
      $table->json('member_snapshot')->nullable();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['status', 'counsellor_id']);
      $table->index(['client_email']);
    });

    Schema::create('counselling_appointments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('counsellor_id')->nullable()->constrained('counselling_counsellors')->nullOnDelete();
      $table->unsignedInteger('session_number')->default(1);
      $table->string('status', 32)->default('scheduled'); // scheduled|confirmed|completed|missed|cancelled|rescheduled
      $table->string('format', 32)->default('virtual');
      $table->timestamp('starts_at');
      $table->timestamp('ends_at')->nullable();
      $table->string('timezone', 64)->default('UTC');
      $table->string('meeting_link', 500)->nullable();
      $table->string('meeting_platform', 40)->nullable();
      $table->string('location')->nullable();
      $table->timestamp('reminder_sent_at')->nullable();
      $table->timestamp('attended_at')->nullable();
      $table->text('notes')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['starts_at', 'counsellor_id']);
    });

    Schema::create('counselling_payments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('service_id')->constrained('counselling_services')->restrictOnDelete();
      $table->string('status', 32)->default('pending'); // pending|paid|refunded|failed|cancelled
      $table->decimal('amount', 12, 2)->default(0);
      $table->string('currency', 3)->default('USD');
      $table->string('client_type', 32)->default('visitor');
      $table->string('payment_reference')->nullable();
      $table->string('provider', 40)->nullable();
      $table->timestamp('paid_at')->nullable();
      $table->timestamp('refunded_at')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_documents', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('title');
      $table->string('disk_path')->nullable();
      $table->string('mime_type', 120)->nullable();
      $table->unsignedBigInteger('size_bytes')->nullable();
      $table->string('visibility', 32)->default('case'); // case|counsellor|admin
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_notes', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
      $table->string('visibility', 32)->default('counsellor'); // counsellor|client|admin
      $table->text('body');
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('counselling_messages', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
      $table->string('sender_role', 32); // client|counsellor|admin
      $table->text('body');
      $table->json('attachments')->nullable();
      $table->timestamp('read_at')->nullable();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['case_id', 'read_at']);
    });

    Schema::create('counselling_case_events', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('event_type', 80);
      $table->string('title');
      $table->text('description')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamp('occurred_at');
      $table->timestamps();
    });

    Schema::create('counselling_feedback', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('case_id')->constrained('counselling_cases')->cascadeOnDelete();
      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->unsignedTinyInteger('rating')->nullable();
      $table->text('comment')->nullable();
      $table->timestamps();
      $table->softDeletes();
      $table->unique(['case_id', 'user_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('counselling_feedback');
    Schema::dropIfExists('counselling_case_events');
    Schema::dropIfExists('counselling_messages');
    Schema::dropIfExists('counselling_notes');
    Schema::dropIfExists('counselling_documents');
    Schema::dropIfExists('counselling_payments');
    Schema::dropIfExists('counselling_appointments');
    Schema::dropIfExists('counselling_cases');
    Schema::dropIfExists('counselling_counsellor_availability');
    Schema::dropIfExists('counselling_counsellors');
    Schema::dropIfExists('counselling_services');
    Schema::dropIfExists('counselling_categories');
  }
};
