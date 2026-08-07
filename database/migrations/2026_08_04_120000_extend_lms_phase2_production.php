<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 LMS production extensions — additive only.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('lms_courses', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_courses', 'audience')) {
        $table->string('audience', 32)->default('both')->after('access_scope');
      }
      if (! Schema::hasColumn('lms_courses', 'difficulty')) {
        $table->string('difficulty', 32)->nullable()->after('level_id');
      }
      if (! Schema::hasColumn('lms_courses', 'estimated_completion_minutes')) {
        $table->unsignedInteger('estimated_completion_minutes')->nullable()->after('duration_minutes');
      }
      if (! Schema::hasColumn('lms_courses', 'sort_order')) {
        $table->unsignedInteger('sort_order')->default(0)->after('is_recommended');
      }
      if (! Schema::hasColumn('lms_courses', 'thumbnail_media_id')) {
        $table->foreignId('thumbnail_media_id')->nullable()->after('cover_media_id')
          ->constrained('cms_media')->nullOnDelete();
      }
      if (! Schema::hasColumn('lms_courses', 'youtube_playlist_url')) {
        $table->string('youtube_playlist_url', 500)->nullable()->after('trailer_youtube_url');
      }
      if (! Schema::hasColumn('lms_courses', 'requirements')) {
        $table->json('requirements')->nullable()->after('description');
      }
      if (! Schema::hasColumn('lms_courses', 'learning_objectives')) {
        $table->json('learning_objectives')->nullable()->after('requirements');
      }
      if (! Schema::hasColumn('lms_courses', 'seo_keywords')) {
        $table->json('seo_keywords')->nullable()->after('seo_description');
      }
      if (! Schema::hasColumn('lms_courses', 'visitor_free')) {
        $table->boolean('visitor_free')->default(false)->after('is_free');
      }
      if (! Schema::hasColumn('lms_courses', 'member_free')) {
        $table->boolean('member_free')->default(false)->after('visitor_free');
      }
      if (! Schema::hasColumn('lms_courses', 'assessment_required')) {
        $table->boolean('assessment_required')->default(false)->after('certificate_auto_issue');
      }
      if (! Schema::hasColumn('lms_courses', 'assignment_required')) {
        $table->boolean('assignment_required')->default(false)->after('assessment_required');
      }
      if (! Schema::hasColumn('lms_courses', 'passing_score')) {
        $table->decimal('passing_score', 5, 2)->nullable()->after('assignment_required');
      }
      if (! Schema::hasColumn('lms_courses', 'max_attempts')) {
        $table->unsignedInteger('max_attempts')->nullable()->after('passing_score');
      }
      if (! Schema::hasColumn('lms_courses', 'completion_rule')) {
        $table->string('completion_rule', 40)->default('all_mandatory_lessons')->after('max_attempts');
      }
    });

    Schema::table('lms_course_categories', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_course_categories', 'seo_title')) {
        $table->string('seo_title')->nullable()->after('description');
      }
      if (! Schema::hasColumn('lms_course_categories', 'seo_description')) {
        $table->text('seo_description')->nullable()->after('seo_title');
      }
      if (! Schema::hasColumn('lms_course_categories', 'is_visible')) {
        $table->boolean('is_visible')->default(true)->after('status');
      }
    });

    Schema::table('lms_enrollments', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_enrollments', 'expired_at')) {
        $table->timestamp('expired_at')->nullable()->after('completed_at');
      }
      if (! Schema::hasColumn('lms_enrollments', 'cancelled_at')) {
        $table->timestamp('cancelled_at')->nullable()->after('expired_at');
      }
      if (! Schema::hasColumn('lms_enrollments', 'locked_at')) {
        $table->timestamp('locked_at')->nullable()->after('cancelled_at');
      }
      if (! Schema::hasColumn('lms_enrollments', 'restarted_at')) {
        $table->timestamp('restarted_at')->nullable()->after('locked_at');
      }
      if (! Schema::hasColumn('lms_enrollments', 'last_accessed_at')) {
        $table->timestamp('last_accessed_at')->nullable()->after('restarted_at');
      }
    });

    if (! Schema::hasTable('lms_assignments')) {
      Schema::create('lms_assignments', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
        $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->nullOnDelete();
        $table->foreignId('module_id')->nullable()->constrained('lms_modules')->nullOnDelete();
        $table->string('title');
        $table->string('slug');
        $table->string('type', 32)->default('mixed'); // objective|essay|upload|mixed
        $table->text('instructions')->nullable();
        $table->text('objective')->nullable();
        $table->json('rubric')->nullable();
        $table->unsignedInteger('max_score')->default(100);
        $table->decimal('pass_mark', 5, 2)->default(70);
        $table->unsignedInteger('max_attempts')->default(3);
        $table->boolean('allow_resubmission')->default(true);
        $table->boolean('allow_attachments')->default(true);
        $table->unsignedInteger('max_attachments')->default(5);
        $table->timestamp('due_at')->nullable();
        $table->boolean('is_required')->default(true);
        $table->string('status', 32)->default('published');
        $table->unsignedInteger('sort_order')->default(0);
        $table->json('metadata')->nullable();
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['course_id', 'slug']);
      });
    }

    if (! Schema::hasTable('lms_assignment_submissions')) {
      Schema::create('lms_assignment_submissions', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->foreignId('assignment_id')->constrained('lms_assignments')->cascadeOnDelete();
        $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->unsignedInteger('attempt_number')->default(1);
        $table->string('status', 32)->default('pending'); // pending|submitted|returned|passed|failed
        $table->longText('essay_body')->nullable();
        $table->json('objective_answers')->nullable();
        $table->json('attachments')->nullable();
        $table->decimal('score', 8, 2)->nullable();
        $table->decimal('max_score', 8, 2)->nullable();
        $table->text('teacher_comments')->nullable();
        $table->timestamp('submitted_at')->nullable();
        $table->timestamp('returned_at')->nullable();
        $table->timestamp('graded_at')->nullable();
        $table->foreignId('graded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['assignment_id', 'enrollment_id', 'attempt_number'], 'lms_assignment_attempt_unique');
      });
    }
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_assignment_submissions');
    Schema::dropIfExists('lms_assignments');

    Schema::table('lms_enrollments', function (Blueprint $table): void {
      foreach (['expired_at', 'cancelled_at', 'locked_at', 'restarted_at', 'last_accessed_at'] as $col) {
        if (Schema::hasColumn('lms_enrollments', $col)) {
          $table->dropColumn($col);
        }
      }
    });

    Schema::table('lms_course_categories', function (Blueprint $table): void {
      foreach (['seo_title', 'seo_description', 'is_visible'] as $col) {
        if (Schema::hasColumn('lms_course_categories', $col)) {
          $table->dropColumn($col);
        }
      }
    });

    Schema::table('lms_courses', function (Blueprint $table): void {
      $cols = [
        'audience', 'difficulty', 'estimated_completion_minutes', 'sort_order',
        'youtube_playlist_url', 'requirements', 'learning_objectives', 'seo_keywords',
        'visitor_free', 'member_free', 'assessment_required', 'assignment_required',
        'passing_score', 'max_attempts', 'completion_rule',
      ];
      foreach ($cols as $col) {
        if (Schema::hasColumn('lms_courses', $col)) {
          $table->dropColumn($col);
        }
      }
      if (Schema::hasColumn('lms_courses', 'thumbnail_media_id')) {
        $table->dropConstrainedForeignId('thumbnail_media_id');
      }
    });
  }
};
