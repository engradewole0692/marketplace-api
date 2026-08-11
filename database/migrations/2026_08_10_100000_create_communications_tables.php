<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('communication_settings', function (Blueprint $table): void {
      $table->id();
      $table->string('ministry_email')->nullable();
      $table->string('reply_to_email')->nullable();
      $table->string('reply_to_name')->nullable();
      $table->string('from_name')->nullable();
      $table->json('branding')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
    });

    Schema::create('communication_routes', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('section', 64);
      $table->string('event_key', 128)->nullable();
      $table->string('label');
      $table->string('recipient_role', 16)->default('to');
      $table->string('recipient_type', 32);
      $table->string('email')->nullable();
      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('include_section_fallback')->default(false);
      $table->boolean('include_ministry_fallback')->default(false);
      $table->boolean('is_active')->default(true);
      $table->json('metadata')->nullable();
      $table->timestamps();

      $table->index(['section', 'event_key', 'is_active']);
      $table->index(['recipient_type', 'is_active']);
    });

    Schema::create('communication_templates', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('slug')->unique();
      $table->string('name');
      $table->string('section', 64);
      $table->string('event_key', 128);
      $table->text('description')->nullable();
      $table->string('subject');
      $table->longText('html_body');
      $table->longText('text_body')->nullable();
      $table->json('available_variables')->nullable();
      $table->json('sample_variables')->nullable();
      $table->boolean('is_active')->default(true);
      $table->boolean('is_system')->default(false);
      $table->timestamps();

      $table->index(['section', 'event_key']);
      $table->index(['is_active', 'section']);
    });

    Schema::create('communication_email_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('template_id')->nullable()->constrained('communication_templates')->nullOnDelete();
      $table->string('event_key', 128);
      $table->string('section', 64)->nullable();
      $table->string('recipient_email');
      $table->string('sender_email')->nullable();
      $table->string('subject');
      $table->string('status', 32)->default('queued');
      $table->boolean('is_test')->default(false);
      $table->text('error_message')->nullable();
      $table->string('related_type')->nullable();
      $table->string('related_id')->nullable();
      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamp('sent_at')->nullable();
      $table->timestamp('failed_at')->nullable();
      $table->timestamps();

      $table->index(['status', 'created_at']);
      $table->index(['section', 'event_key']);
      $table->index('recipient_email');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('communication_email_logs');
    Schema::dropIfExists('communication_templates');
    Schema::dropIfExists('communication_routes');
    Schema::dropIfExists('communication_settings');
  }
};
