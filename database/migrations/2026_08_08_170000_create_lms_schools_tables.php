<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_schools', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('slug')->unique();
      $table->string('title');
      $table->string('subtitle')->nullable();
      $table->text('summary')->nullable();
      $table->longText('description')->nullable();
      $table->string('status', 32)->default('draft');
      $table->unsignedInteger('sort_order')->default(0);
      $table->decimal('member_price', 12, 2)->default(0);
      $table->decimal('public_price', 12, 2)->default(0);
      $table->string('currency', 8)->default('USD');
      $table->boolean('certificate_enabled')->default(true);
      $table->boolean('sequential_progression')->default(true);
      $table->foreignId('cover_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('thumbnail_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamp('published_at')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['status', 'sort_order']);
    });

    Schema::create('lms_school_enrollments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('school_id')->constrained('lms_schools')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->string('learner_type', 32)->default('public');
      $table->string('status', 32)->default('active');
      $table->decimal('price_paid', 12, 2)->nullable();
      $table->string('currency', 8)->default('USD');
      $table->string('payment_reference')->nullable();
      $table->timestamp('enrolled_at')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->timestamp('expired_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->decimal('progress_percent', 5, 2)->default(0);
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['school_id', 'user_id']);
      $table->index(['user_id', 'status']);
    });

    Schema::table('lms_courses', function (Blueprint $table): void {
      $table->foreignId('school_id')->nullable()->after('primary_ministry_id')
        ->constrained('lms_schools')->nullOnDelete();
      $table->index(['school_id', 'status']);
    });
  }

  public function down(): void
  {
    Schema::table('lms_courses', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('school_id');
    });
    Schema::dropIfExists('lms_school_enrollments');
    Schema::dropIfExists('lms_schools');
  }
};
