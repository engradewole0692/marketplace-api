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
    Schema::table('lms_courses', function (Blueprint $table): void {
      $table->string('course_code', 40)->nullable()->unique()->after('uuid');
      $table->foreignId('subcategory_id')->nullable()->after('category_id')
        ->constrained('lms_course_categories')->nullOnDelete();
      $table->foreignId('banner_media_id')->nullable()->after('cover_media_id')
        ->constrained('cms_media')->nullOnDelete();
      $table->timestamp('scheduled_publish_at')->nullable()->after('published_at')->index();
      $table->string('access_scope', 40)->default('general')->after('status');
      $table->unsignedBigInteger('primary_ministry_id')->nullable()->after('access_scope');
      $table->unsignedInteger('certificate_min_score')->nullable()->after('certificate_requires_assessment_pass');
      $table->unsignedInteger('certificate_min_completion_percent')->nullable()->after('certificate_min_score');
      $table->boolean('certificate_auto_issue')->default(true)->after('certificate_min_completion_percent');
    });

    if (Schema::hasTable('cms_ministries')) {
      Schema::table('lms_courses', function (Blueprint $table): void {
        $table->foreign('primary_ministry_id')->references('id')->on('cms_ministries')->nullOnDelete();
      });
    }

    Schema::create('lms_course_ministry', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->unsignedBigInteger('ministry_id');
      $table->timestamps();
      $table->unique(['course_id', 'ministry_id']);
    });

    if (Schema::hasTable('cms_ministries')) {
      Schema::table('lms_course_ministry', function (Blueprint $table): void {
        $table->foreign('ministry_id')->references('id')->on('cms_ministries')->cascadeOnDelete();
      });
    }

    Schema::table('lms_lesson_resources', function (Blueprint $table): void {
      $table->string('access_level', 40)->default('free')->after('is_downloadable');
      $table->boolean('is_preview_only')->default(false)->after('access_level');
    });

    Schema::table('lms_assessments', function (Blueprint $table): void {
      $table->foreignId('module_id')->nullable()->after('course_id')
        ->constrained('lms_modules')->nullOnDelete();
    });

    // Backfill course codes for existing rows.
    $courses = DB::table('lms_courses')->whereNull('course_code')->orderBy('id')->get(['id']);
    foreach ($courses as $course) {
      DB::table('lms_courses')->where('id', $course->id)->update([
        'course_code' => sprintf('KC-%05d', $course->id),
      ]);
    }
  }

  public function down(): void
  {
    Schema::table('lms_assessments', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('module_id');
    });

    Schema::table('lms_lesson_resources', function (Blueprint $table): void {
      $table->dropColumn(['access_level', 'is_preview_only']);
    });

    Schema::dropIfExists('lms_course_ministry');

    Schema::table('lms_courses', function (Blueprint $table): void {
      if (Schema::hasColumn('lms_courses', 'primary_ministry_id')) {
        $table->dropForeign(['primary_ministry_id']);
      }
      $table->dropConstrainedForeignId('subcategory_id');
      $table->dropConstrainedForeignId('banner_media_id');
      $table->dropColumn([
        'course_code',
        'scheduled_publish_at',
        'access_scope',
        'primary_ministry_id',
        'certificate_min_score',
        'certificate_min_completion_percent',
        'certificate_auto_issue',
      ]);
    });
  }
};
