<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('members', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('membership_number')->unique();
      $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
      $table->string('photo_path')->nullable();
      $table->string('title', 40)->nullable();
      $table->string('first_name');
      $table->string('middle_name')->nullable();
      $table->string('last_name');
      $table->string('display_name')->nullable();
      $table->string('gender', 20)->nullable();
      $table->date('date_of_birth')->nullable();
      $table->string('phone', 40)->nullable();
      $table->string('alternate_phone', 40)->nullable();
      $table->string('email')->nullable()->index();
      $table->string('occupation')->nullable();
      $table->string('organization')->nullable();
      $table->string('marketplace_sector')->nullable()->index();
      $table->json('skills')->nullable();
      $table->json('languages')->nullable();
      $table->text('biography')->nullable();
      $table->unsignedBigInteger('country_id')->nullable()->index();
      $table->unsignedBigInteger('region_id')->nullable()->index();
      $table->unsignedBigInteger('ministry_id')->nullable()->index();
      $table->string('status', 40)->default('application_submitted')->index();
      $table->string('approval_status', 40)->default('pending')->index();
      $table->date('joined_at')->nullable()->index();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['first_name', 'last_name']);
      $table->index(['status', 'approval_status']);
    });

    Schema::create('member_status_transitions', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('from_status', 40)->nullable();
      $table->string('to_status', 40);
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->text('reason')->nullable();
      $table->timestamps();

      $table->index(['member_id', 'created_at']);
    });

    Schema::create('member_contacts', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('contact_type', 40);
      $table->string('name');
      $table->string('relationship', 80)->nullable();
      $table->string('phone', 40)->nullable();
      $table->string('email')->nullable();
      $table->boolean('is_primary')->default(false);
      $table->timestamps();
    });

    Schema::create('member_addresses', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('address_type', 40)->default('home');
      $table->string('address_line_1')->nullable();
      $table->string('address_line_2')->nullable();
      $table->string('city')->nullable();
      $table->string('state')->nullable();
      $table->string('postal_code', 20)->nullable();
      $table->string('country_code', 3)->nullable();
      $table->boolean('is_primary')->default(false);
      $table->timestamps();
    });

    Schema::create('member_notes', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
      $table->text('body');
      $table->boolean('is_private')->default(true);
      $table->timestamps();
      $table->softDeletes();

      $table->index(['member_id', 'created_at']);
    });

    Schema::create('member_tags', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('color', 20)->nullable();
      $table->timestamps();
    });

    Schema::create('member_tag_member', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->foreignId('member_tag_id')->constrained()->cascadeOnDelete();
      $table->timestamps();

      $table->unique(['member_id', 'member_tag_id']);
    });

    Schema::create('member_documents', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
      $table->string('document_type', 40);
      $table->string('title');
      $table->string('file_path');
      $table->string('file_name');
      $table->string('mime_type', 120)->nullable();
      $table->unsignedBigInteger('file_size')->nullable();
      $table->string('disk', 40)->default('local');
      $table->timestamps();
      $table->softDeletes();

      $table->index(['member_id', 'document_type']);
    });

    Schema::create('member_timelines', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->string('event_type', 60);
      $table->text('description');
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamp('occurred_at');
      $table->timestamps();

      $table->index(['member_id', 'occurred_at']);
    });

    Schema::create('member_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->string('event_type', 60)->index();
      $table->foreignId('member_id')->constrained()->cascadeOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->json('metadata')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamps();

      $table->index(['member_id', 'created_at']);
    });

    Schema::create('membership_number_sequences', function (Blueprint $table): void {
      $table->id();
      $table->unsignedSmallInteger('year');
      $table->unsignedBigInteger('last_sequence')->default(0);
      $table->timestamps();

      $table->unique('year');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('membership_number_sequences');
    Schema::dropIfExists('member_audit_logs');
    Schema::dropIfExists('member_timelines');
    Schema::dropIfExists('member_documents');
    Schema::dropIfExists('member_tag_member');
    Schema::dropIfExists('member_tags');
    Schema::dropIfExists('member_notes');
    Schema::dropIfExists('member_addresses');
    Schema::dropIfExists('member_contacts');
    Schema::dropIfExists('member_status_transitions');
    Schema::dropIfExists('members');
  }
};
