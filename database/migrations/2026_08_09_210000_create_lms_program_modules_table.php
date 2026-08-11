<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_program_modules', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('container_type', 32);
      $table->unsignedBigInteger('school_id')->nullable();
      $table->unsignedBigInteger('category_id')->nullable();
      $table->string('title');
      $table->string('slug');
      $table->text('description')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->string('status', 32)->default('published');
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->foreign('school_id')->references('id')->on('lms_schools')->cascadeOnDelete();
      $table->foreign('category_id')->references('id')->on('lms_course_categories')->cascadeOnDelete();
      $table->unique(['container_type', 'school_id', 'slug']);
      $table->unique(['container_type', 'category_id', 'slug']);
      $table->index(['container_type', 'school_id', 'sort_order']);
      $table->index(['container_type', 'category_id', 'sort_order']);
    });

    Schema::table('lms_courses', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_courses', 'program_module_id')) {
        $table->foreignId('program_module_id')
          ->nullable()
          ->after('school_id')
          ->constrained('lms_program_modules')
          ->nullOnDelete();
      }
    });

    Schema::table('lms_course_categories', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_course_categories', 'is_free_learning_hub')) {
        $table->boolean('is_free_learning_hub')->default(false)->after('is_visible');
      }
    });
  }

  public function down(): void
  {
    Schema::table('lms_courses', function (Blueprint $table): void {
      if (Schema::hasColumn('lms_courses', 'program_module_id')) {
        $table->dropConstrainedForeignId('program_module_id');
      }
    });

    Schema::table('lms_course_categories', function (Blueprint $table): void {
      if (Schema::hasColumn('lms_course_categories', 'is_free_learning_hub')) {
        $table->dropColumn('is_free_learning_hub');
      }
    });

    Schema::dropIfExists('lms_program_modules');
  }
};
