<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_course_categories', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->text('description')->nullable();
      $table->foreignId('parent_id')->nullable()->constrained('lms_course_categories')->nullOnDelete();
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('status', 40)->default('active')->index();
      $table->string('icon')->nullable();
      $table->foreignId('cover_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_levels', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->text('description')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('status', 40)->default('active')->index();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_languages', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('code', 12)->unique();
      $table->string('status', 40)->default('active')->index();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_tags', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('status', 40)->default('active')->index();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_instructors', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('title')->nullable();
      $table->longText('bio')->nullable();
      $table->foreignId('photo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('email')->nullable();
      $table->string('website_url')->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_courses', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('category_id')->nullable()->constrained('lms_course_categories')->nullOnDelete();
      $table->foreignId('level_id')->nullable()->constrained('lms_course_levels')->nullOnDelete();
      $table->foreignId('language_id')->nullable()->constrained('lms_course_languages')->nullOnDelete();
      $table->string('title');
      $table->string('slug')->unique();
      $table->string('subtitle')->nullable();
      $table->text('summary')->nullable();
      $table->longText('description')->nullable();
      $table->string('status', 40)->default('draft')->index();
      $table->boolean('is_featured')->default(false)->index();
      $table->boolean('is_popular')->default(false)->index();
      $table->boolean('is_recommended')->default(false)->index();
      $table->foreignId('cover_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('trailer_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('trailer_youtube_url')->nullable();
      $table->decimal('member_price', 12, 2)->nullable();
      $table->decimal('public_price', 12, 2)->nullable();
      $table->boolean('is_free')->default(false)->index();
      $table->decimal('promotional_price', 12, 2)->nullable();
      $table->timestamp('promotional_starts_at')->nullable();
      $table->timestamp('promotional_ends_at')->nullable();
      $table->string('currency', 3)->default('USD');
      $table->unsignedInteger('enrollment_count')->default(0);
      $table->decimal('average_rating', 3, 2)->nullable();
      $table->unsignedInteger('review_count')->default(0);
      $table->unsignedInteger('duration_minutes')->nullable();
      $table->timestamp('published_at')->nullable()->index();
      $table->string('seo_title')->nullable();
      $table->text('seo_description')->nullable();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['status', 'is_featured']);
      $table->index(['category_id', 'status']);
    });

    Schema::create('lms_course_tag', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('tag_id')->constrained('lms_course_tags')->cascadeOnDelete();
      $table->unique(['course_id', 'tag_id']);
    });

    Schema::create('lms_course_instructor', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('instructor_id')->constrained('lms_instructors')->cascadeOnDelete();
      $table->boolean('is_primary')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('role_label')->nullable();
      $table->unique(['course_id', 'instructor_id']);
    });

    Schema::create('lms_modules', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->string('title');
      $table->string('slug');
      $table->text('description')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('status', 40)->default('draft')->index();
      $table->boolean('is_preview')->default(false);
      $table->unsignedInteger('duration_minutes')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['course_id', 'slug']);
      $table->index(['course_id', 'sort_order']);
    });

    Schema::create('lms_lessons', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('module_id')->constrained('lms_modules')->cascadeOnDelete();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->string('title');
      $table->string('slug');
      $table->text('summary')->nullable();
      $table->longText('content')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('status', 40)->default('draft')->index();
      $table->string('lesson_type', 40)->default('video')->index();
      $table->boolean('is_preview')->default(false);
      $table->unsignedInteger('duration_minutes')->nullable();
      $table->string('video_source', 40)->default('none');
      $table->string('youtube_video_id')->nullable();
      $table->string('youtube_url')->nullable();
      $table->foreignId('video_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->text('embed_html')->nullable();
      $table->boolean('is_mandatory')->default(true);
      $table->unsignedTinyInteger('completion_threshold_percent')->default(100);
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['module_id', 'slug']);
      $table->index(['course_id', 'sort_order']);
    });

    Schema::create('lms_lesson_resources', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
      $table->string('title');
      $table->string('resource_type', 40)->default('pdf');
      $table->foreignId('file_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('external_url')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('is_downloadable')->default(true);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_downloads', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->string('title');
      $table->text('description')->nullable();
      $table->foreignId('file_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('external_url')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->boolean('is_public')->default(false);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_enrollments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->string('learner_type', 40)->default('public')->index();
      $table->string('status', 40)->default('active')->index();
      $table->timestamp('enrolled_at')->useCurrent();
      $table->timestamp('completed_at')->nullable();
      $table->decimal('progress_percent', 5, 2)->default(0);
      $table->decimal('price_paid', 12, 2)->nullable();
      $table->string('currency', 3)->nullable();
      $table->string('coupon_code')->nullable();
      $table->string('payment_reference')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['course_id', 'user_id']);
      $table->index(['user_id', 'status']);
    });

    Schema::create('lms_lesson_progress', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
      $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
      $table->string('status', 40)->default('not_started')->index();
      $table->decimal('progress_percent', 5, 2)->default(0);
      $table->timestamp('started_at')->nullable();
      $table->timestamp('completed_at')->nullable();
      $table->unsignedInteger('last_position_seconds')->default(0);
      $table->timestamps();

      $table->unique(['enrollment_id', 'lesson_id']);
    });

    Schema::create('lms_certificates', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->string('certificate_number')->unique();
      $table->string('verification_code')->unique();
      $table->string('status', 40)->default('pending')->index();
      $table->timestamp('issued_at')->nullable();
      $table->timestamp('revoked_at')->nullable();
      $table->foreignId('certificate_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_reviews', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
      $table->unsignedTinyInteger('rating');
      $table->string('title')->nullable();
      $table->text('body')->nullable();
      $table->string('status', 40)->default('pending')->index();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['course_id', 'user_id']);
    });

    Schema::create('lms_wishlists', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->timestamps();

      $table->unique(['user_id', 'course_id']);
    });

    Schema::create('lms_bookmarks', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
      $table->text('note')->nullable();
      $table->timestamps();

      $table->unique(['user_id', 'lesson_id']);
    });

    Schema::create('lms_announcements', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
      $table->string('title');
      $table->longText('body');
      $table->string('status', 40)->default('draft')->index();
      $table->timestamp('published_at')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_faqs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->string('question');
      $table->text('answer');
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_coupons', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('code')->unique();
      $table->string('name');
      $table->string('discount_type', 40)->default('percent');
      $table->decimal('discount_value', 12, 2);
      $table->string('applies_to', 40)->default('all');
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
      $table->unsignedInteger('max_redemptions')->nullable();
      $table->unsignedInteger('redeemed_count')->default(0);
      $table->timestamp('starts_at')->nullable();
      $table->timestamp('ends_at')->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_course_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('event_type', 80)->index();
      $table->text('description')->nullable();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->json('metadata')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_course_audit_logs');
    Schema::dropIfExists('lms_coupons');
    Schema::dropIfExists('lms_course_faqs');
    Schema::dropIfExists('lms_announcements');
    Schema::dropIfExists('lms_bookmarks');
    Schema::dropIfExists('lms_wishlists');
    Schema::dropIfExists('lms_reviews');
    Schema::dropIfExists('lms_certificates');
    Schema::dropIfExists('lms_lesson_progress');
    Schema::dropIfExists('lms_enrollments');
    Schema::dropIfExists('lms_course_downloads');
    Schema::dropIfExists('lms_lesson_resources');
    Schema::dropIfExists('lms_lessons');
    Schema::dropIfExists('lms_modules');
    Schema::dropIfExists('lms_course_instructor');
    Schema::dropIfExists('lms_course_tag');
    Schema::dropIfExists('lms_courses');
    Schema::dropIfExists('lms_instructors');
    Schema::dropIfExists('lms_course_tags');
    Schema::dropIfExists('lms_course_languages');
    Schema::dropIfExists('lms_course_levels');
    Schema::dropIfExists('lms_course_categories');
  }
};
