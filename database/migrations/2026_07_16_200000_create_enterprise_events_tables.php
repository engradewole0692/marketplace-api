<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('event_categories', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('ministry_id')->nullable()->constrained('cms_ministries')->nullOnDelete();
      $table->string('name');
      $table->string('slug');
      $table->text('description')->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['ministry_id', 'slug']);
      $table->index(['ministry_id', 'status']);
    });

    Schema::create('venues', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->text('description')->nullable();
      $table->string('address_line_1')->nullable();
      $table->string('address_line_2')->nullable();
      $table->string('city')->nullable()->index();
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
      $table->string('postal_code', 30)->nullable();
      $table->decimal('latitude', 10, 7)->nullable();
      $table->decimal('longitude', 10, 7)->nullable();
      $table->unsignedInteger('capacity')->nullable();
      $table->string('contact_name')->nullable();
      $table->string('contact_email')->nullable();
      $table->string('contact_phone', 40)->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['country_id', 'region_id']);
    });

    Schema::create('speakers', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('title')->nullable();
      $table->string('organization')->nullable();
      $table->longText('bio')->nullable();
      $table->foreignId('photo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('email')->nullable();
      $table->string('phone', 40)->nullable();
      $table->string('website_url')->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('events', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('ministry_id')->nullable()->constrained('cms_ministries')->nullOnDelete();
      $table->foreignId('event_category_id')->nullable()->constrained('event_categories')->nullOnDelete();
      $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
      $table->string('title');
      $table->string('slug')->unique();
      $table->string('theme')->nullable();
      $table->string('theme_scripture')->nullable();
      $table->string('theme_color', 30)->nullable();
      $table->foreignId('banner_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->text('summary')->nullable();
      $table->longText('description')->nullable();
      $table->timestamp('starts_at')->nullable();
      $table->timestamp('ends_at')->nullable();
      $table->string('timezone', 64)->default('UTC');
      $table->timestamp('registration_opens_at')->nullable();
      $table->timestamp('registration_deadline')->nullable()->index();
      $table->unsignedInteger('capacity')->nullable();
      $table->boolean('check_in_enabled')->default(false);
      $table->boolean('certificate_enabled')->default(false);
      $table->boolean('attendance_required')->default(false);
      $table->string('visibility', 40)->default('public')->index();
      $table->string('status', 40)->default('draft')->index();
      $table->timestamp('published_at')->nullable();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['ministry_id', 'event_category_id']);
      $table->index(['country_id', 'region_id']);
      $table->index(['status', 'visibility']);
      $table->index(['starts_at', 'ends_at']);
    });

    Schema::create('event_speaker', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('speaker_id')->constrained('speakers')->cascadeOnDelete();
      $table->string('role', 80)->default('speaker');
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['event_id', 'speaker_id']);
    });

    Schema::create('event_sessions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('speaker_id')->nullable()->constrained('speakers')->nullOnDelete();
      $table->string('title');
      $table->string('session_type', 60)->default('session')->index();
      $table->text('description')->nullable();
      $table->timestamp('starts_at')->nullable();
      $table->timestamp('ends_at')->nullable();
      $table->string('location')->nullable();
      $table->unsignedInteger('capacity')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'starts_at']);
    });

    Schema::create('event_gallery_items', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('title')->nullable();
      $table->string('caption')->nullable();
      $table->string('media_type', 40)->default('image');
      $table->foreignId('media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('external_url')->nullable();
      $table->string('alt_text')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('is_featured')->default(false);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'sort_order']);
    });

    Schema::create('event_resources', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('title');
      $table->string('resource_type', 60)->default('file')->index();
      $table->text('description')->nullable();
      $table->foreignId('media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('external_url')->nullable();
      $table->boolean('is_public')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'resource_type']);
    });

    Schema::create('event_faqs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('question');
      $table->text('answer');
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('is_active')->default(true);
      $table->timestamps();

      $table->index(['event_id', 'sort_order']);
    });

    Schema::create('event_sponsors', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('name');
      $table->string('slug')->nullable();
      $table->foreignId('logo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('website_url')->nullable();
      $table->text('description')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'sort_order']);
    });

    Schema::create('event_registration_field_settings', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('field_key', 80);
      $table->string('label')->nullable();
      $table->boolean('is_enabled')->default(true);
      $table->boolean('is_required')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->unique(['event_id', 'field_key']);
      $table->index(['event_id', 'is_enabled']);
    });

    Schema::create('event_registration_questions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->string('field_key', 80)->nullable();
      $table->string('question');
      $table->text('help_text')->nullable();
      $table->string('answer_type', 40)->default('text');
      $table->json('options')->nullable();
      $table->boolean('is_enabled')->default(true);
      $table->boolean('is_required')->default(false);
      $table->string('maps_to_member_field', 80)->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'is_enabled']);
      $table->index(['event_id', 'sort_order']);
    });

    Schema::create('event_registrations', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->string('guest_name')->nullable();
      $table->string('guest_email')->nullable()->index();
      $table->string('guest_phone', 40)->nullable();
      $table->string('registration_number')->unique();
      $table->string('status', 40)->default('submitted')->index();
      $table->string('source', 80)->default('public_form')->index();
      $table->string('emergency_contact_name')->nullable();
      $table->string('emergency_contact_relationship', 80)->nullable();
      $table->string('emergency_contact_phone', 40)->nullable();
      $table->date('arrival_date')->nullable();
      $table->date('departure_date')->nullable();
      $table->boolean('accommodation_required')->default(false);
      $table->boolean('airport_pickup_required')->default(false);
      $table->text('dietary_requirements')->nullable();
      $table->text('medical_notes')->nullable();
      $table->boolean('volunteer_interest')->default(false);
      $table->text('prayer_requests')->nullable();
      $table->text('additional_notes')->nullable();
      $table->boolean('consent_accepted')->default(false);
      $table->timestamp('consent_accepted_at')->nullable();
      $table->timestamp('submitted_at')->nullable();
      $table->timestamp('approved_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'member_id']);
      $table->index(['event_id', 'status']);
      $table->index(['member_id', 'created_at']);
      $table->index(['arrival_date', 'departure_date']);
    });

    Schema::create('event_registration_question_answers', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('question_id')->constrained('event_registration_questions')->cascadeOnDelete();
      $table->text('answer_text')->nullable();
      $table->json('answer_json')->nullable();
      $table->timestamps();

      $table->unique(['registration_id', 'question_id'], 'erqa_reg_question_unique');
    });

    Schema::create('event_registration_status_transitions', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->string('from_status', 40)->nullable();
      $table->string('to_status', 40);
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->text('reason')->nullable();
      $table->timestamps();

      $table->index(['registration_id', 'created_at'], 'erst_reg_created_index');
    });

    Schema::create('event_registration_timelines', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->string('event_type', 80);
      $table->text('description');
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamp('occurred_at');
      $table->timestamps();

      $table->index(['registration_id', 'occurred_at'], 'ertl_reg_occurred_index');
      $table->index('event_type');
    });

    Schema::create('event_registration_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->string('event_type', 80)->index();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->json('metadata')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamps();

      $table->index(['registration_id', 'created_at'], 'eral_reg_created_index');
      $table->index(['event_id', 'created_at'], 'eral_event_created_index');
      $table->index(['member_id', 'created_at'], 'eral_member_created_index');
    });

    Schema::create('event_registration_sequences', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->unsignedBigInteger('last_sequence')->default(0);
      $table->timestamps();

      $table->unique('event_id');
    });

    Schema::create('event_check_in_tokens', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->string('token_hash', 128)->unique();
      $table->timestamp('issued_at')->nullable();
      $table->timestamp('expires_at')->nullable();
      $table->timestamp('last_used_at')->nullable();
      $table->timestamp('revoked_at')->nullable();
      $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->unique(['event_id', 'registration_id']);
      $table->index(['event_id', 'member_id']);
    });

    Schema::create('event_check_ins', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
      $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('method', 40)->default('manual')->index();
      $table->timestamp('checked_in_at');
      $table->text('notes')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'checked_in_at']);
      $table->index(['registration_id', 'checked_in_at']);
      $table->index(['member_id', 'checked_in_at']);
      $table->index(['event_session_id', 'checked_in_at']);
    });

    Schema::create('event_attendance_histories', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
      $table->string('status', 40)->default('present')->index();
      $table->string('source', 80)->default('check_in')->index();
      $table->timestamp('occurred_at');
      $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->text('notes')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'status']);
      $table->index(['member_id', 'occurred_at']);
      $table->index(['event_session_id', 'status']);
    });

    Schema::create('event_certificate_issuances', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
      $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
      $table->string('certificate_number')->unique();
      $table->string('status', 40)->default('issued')->index();
      $table->timestamp('issued_at')->nullable();
      $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('certificate_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->timestamp('revoked_at')->nullable();
      $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->unique(['event_id', 'registration_id']);
      $table->index(['event_id', 'status']);
      $table->index(['member_id', 'issued_at']);
    });

    Schema::create('event_notification_templates', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
      $table->string('name');
      $table->string('trigger', 80)->index();
      $table->string('channel', 40)->default('email')->index();
      $table->string('subject')->nullable();
      $table->longText('body');
      $table->boolean('is_active')->default(true)->index();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['event_id', 'trigger']);
    });

    Schema::create('event_notification_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
      $table->foreignId('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->foreignId('template_id')->nullable()->constrained('event_notification_templates')->nullOnDelete();
      $table->string('channel', 40)->default('email')->index();
      $table->string('recipient')->nullable();
      $table->string('subject')->nullable();
      $table->string('status', 40)->default('pending')->index();
      $table->timestamp('queued_at')->nullable();
      $table->timestamp('sent_at')->nullable();
      $table->timestamp('failed_at')->nullable();
      $table->text('failure_reason')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'status']);
      $table->index(['registration_id', 'status']);
      $table->index(['member_id', 'created_at']);
    });

    Schema::create('event_report_snapshots', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
      $table->string('report_type', 80)->index();
      $table->json('filters')->nullable();
      $table->json('metrics');
      $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('generated_at');
      $table->timestamps();

      $table->index(['event_id', 'report_type']);
      $table->index('generated_at');
    });

    Schema::create('event_export_jobs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
      $table->string('export_type', 80)->index();
      $table->string('format', 20)->index();
      $table->json('filters')->nullable();
      $table->string('status', 40)->default('pending')->index();
      $table->string('file_path')->nullable();
      $table->string('disk', 40)->default('public');
      $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('started_at')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->timestamp('failed_at')->nullable();
      $table->text('failure_reason')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'export_type']);
      $table->index(['requested_by_user_id', 'created_at']);
    });

    Schema::create('event_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->string('event_type', 80)->index();
      $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('subject_type', 120)->nullable()->index();
      $table->unsignedBigInteger('subject_id')->nullable()->index();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->json('metadata')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamps();

      $table->index(['event_id', 'created_at']);
      $table->index(['subject_type', 'subject_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('event_audit_logs');
    Schema::dropIfExists('event_export_jobs');
    Schema::dropIfExists('event_report_snapshots');
    Schema::dropIfExists('event_notification_logs');
    Schema::dropIfExists('event_notification_templates');
    Schema::dropIfExists('event_certificate_issuances');
    Schema::dropIfExists('event_attendance_histories');
    Schema::dropIfExists('event_check_ins');
    Schema::dropIfExists('event_check_in_tokens');
    Schema::dropIfExists('event_registration_sequences');
    Schema::dropIfExists('event_registration_audit_logs');
    Schema::dropIfExists('event_registration_timelines');
    Schema::dropIfExists('event_registration_status_transitions');
    Schema::dropIfExists('event_registration_question_answers');
    Schema::dropIfExists('event_registrations');
    Schema::dropIfExists('event_registration_questions');
    Schema::dropIfExists('event_registration_field_settings');
    Schema::dropIfExists('event_sponsors');
    Schema::dropIfExists('event_faqs');
    Schema::dropIfExists('event_resources');
    Schema::dropIfExists('event_gallery_items');
    Schema::dropIfExists('event_sessions');
    Schema::dropIfExists('event_speaker');
    Schema::dropIfExists('events');
    Schema::dropIfExists('speakers');
    Schema::dropIfExists('venues');
    Schema::dropIfExists('event_categories');
  }
};
