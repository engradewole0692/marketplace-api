<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide communications:
 *   - notifications (member-facing in-app bell)
 *   - announcements (targeted, scheduled, expirable)
 *   - conversations + messages (permission-aware in-app chat)
 *   - bulk_email_jobs + bulk_email_recipients (queued bulk email)
 */
return new class extends Migration
{
  public function up(): void
  {
    // ── IN-APP NOTIFICATIONS ──────────────────────────────────────────────
    if (! Schema::hasTable('platform_notifications')) {
      Schema::create('platform_notifications', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();

        // Targeting — at least one must be set.
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('role_slug', 80)->nullable();        // role-based
        $table->string('target_type', 40)->nullable();      // 'all'|'members'|'visitors'|'staff'|'admins'
        $table->unsignedBigInteger('country_id')->nullable();
        $table->unsignedBigInteger('region_id')->nullable();
        $table->unsignedBigInteger('ministry_id')->nullable();

        $table->string('type', 60)->default('info');        // info|success|warning|alert|message|event|lms|counselling
        $table->string('title', 255);
        $table->text('body');
        $table->string('action_url', 500)->nullable();
        $table->string('icon', 80)->nullable();

        // Related entity (polymorphic)
        $table->string('related_type', 100)->nullable();
        $table->string('related_id', 100)->nullable();

        // Per-recipient read state lives in platform_notification_reads
        $table->boolean('is_read')->default(false);         // used when user_id is set directly
        $table->timestamp('read_at')->nullable();

        // Sender
        $table->unsignedBigInteger('sender_id')->nullable(); // user who triggered it (null = system)

        $table->timestamps();
        $table->softDeletes();

        $table->index(['user_id', 'is_read', 'created_at']);
        $table->index(['target_type', 'created_at']);
        $table->index(['country_id', 'target_type']);
        $table->index(['region_id', 'target_type']);
        $table->index(['ministry_id', 'target_type']);
      });
    }

    // ── ANNOUNCEMENTS ─────────────────────────────────────────────────────
    if (! Schema::hasTable('platform_announcements')) {
      Schema::create('platform_announcements', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('title', 255);
        $table->text('content');
        $table->string('image_path', 500)->nullable();
        $table->string('status', 30)->default('draft');     // draft|published|archived
        $table->string('target_audience', 40)->default('all'); // all|members|visitors|staff|admins|custom
        $table->boolean('show_on_public')->default(false);
        $table->boolean('send_email')->default(false);
        $table->boolean('send_notification')->default(true);

        // Targeting filters (JSON arrays of IDs / slugs)
        $table->json('target_countries')->nullable();
        $table->json('target_regions')->nullable();
        $table->json('target_ministries')->nullable();
        $table->json('target_roles')->nullable();

        $table->timestamp('publish_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->unsignedBigInteger('published_by')->nullable();
        $table->timestamp('published_at')->nullable();

        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamps();
        $table->softDeletes();

        $table->index(['status', 'publish_at', 'expires_at']);
        $table->index(['show_on_public', 'status']);
      });
    }

    // ── IN-APP CONVERSATIONS ──────────────────────────────────────────────
    if (! Schema::hasTable('platform_conversations')) {
      Schema::create('platform_conversations', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('type', 40)->default('direct');      // direct|group|support
        $table->string('subject', 255)->nullable();

        // Module context (optional — counselling, events, lms, etc.)
        $table->string('module', 60)->nullable();
        $table->string('module_entity_type', 100)->nullable();
        $table->string('module_entity_id', 100)->nullable();

        $table->boolean('is_closed')->default(false);
        $table->timestamp('last_message_at')->nullable();

        $table->timestamps();
        $table->softDeletes();

        $table->index(['type', 'last_message_at']);
        $table->index(['module', 'module_entity_type', 'module_entity_id']);
      });
    }

    if (! Schema::hasTable('platform_conversation_participants')) {
      Schema::create('platform_conversation_participants', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('conversation_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role', 40)->default('participant');  // owner|participant
        $table->timestamp('last_read_at')->nullable();
        $table->boolean('is_muted')->default(false);
        $table->timestamps();

        $table->unique(['conversation_id', 'user_id']);
        $table->index(['user_id', 'conversation_id']);
      });
    }

    if (! Schema::hasTable('platform_messages')) {
      Schema::create('platform_messages', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->unsignedBigInteger('conversation_id');
        $table->unsignedBigInteger('sender_id');
        $table->text('body');
        $table->string('type', 30)->default('text');        // text|system|attachment
        $table->boolean('is_deleted')->default(false);

        // Attachments (stored inline as JSON for simplicity)
        $table->json('attachments')->nullable();

        $table->timestamps();

        $table->index(['conversation_id', 'created_at']);
        $table->index(['sender_id']);
      });
    }

    // ── BULK EMAIL JOBS ───────────────────────────────────────────────────
    if (! Schema::hasTable('bulk_email_jobs')) {
      Schema::create('bulk_email_jobs', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('subject', 255);
        $table->text('html_body');
        $table->text('text_body')->nullable();
        $table->string('from_name', 100)->nullable();
        $table->string('from_email', 255)->nullable();

        // Recipient filter snapshot (stored for audit)
        $table->json('recipient_filters');
        $table->unsignedInteger('estimated_count')->default(0);
        $table->unsignedInteger('sent_count')->default(0);
        $table->unsignedInteger('failed_count')->default(0);

        $table->string('status', 30)->default('draft');     // draft|queued|sending|completed|failed|cancelled
        $table->unsignedBigInteger('created_by');
        $table->timestamp('queued_at')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();

        $table->timestamps();
        $table->softDeletes();

        $table->index(['status', 'queued_at']);
        $table->index(['created_by']);
      });
    }

    if (! Schema::hasTable('bulk_email_recipients')) {
      Schema::create('bulk_email_recipients', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('bulk_email_job_id');
        $table->string('email', 255);
        $table->string('name', 255)->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('status', 30)->default('pending');   // pending|sent|failed|bounced
        $table->text('error_message')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();

        $table->index(['bulk_email_job_id', 'status']);
        $table->index(['email']);
      });
    }
  }

  public function down(): void
  {
    Schema::dropIfExists('bulk_email_recipients');
    Schema::dropIfExists('bulk_email_jobs');
    Schema::dropIfExists('platform_messages');
    Schema::dropIfExists('platform_conversation_participants');
    Schema::dropIfExists('platform_conversations');
    Schema::dropIfExists('platform_announcements');
    Schema::dropIfExists('platform_notifications');
  }
};
