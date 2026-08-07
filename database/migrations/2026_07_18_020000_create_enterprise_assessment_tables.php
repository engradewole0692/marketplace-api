<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_questions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('prompt');
      $table->longText('stem')->nullable();
      $table->string('question_type', 40)->index();
      $table->decimal('default_points', 8, 2)->default(1);
      $table->json('correct_payload')->nullable();
      $table->json('metadata')->nullable();
      $table->string('difficulty', 40)->nullable();
      $table->string('status', 40)->default('active')->index();
      $table->text('explanation')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('lms_question_options', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
      $table->string('label');
      $table->text('body')->nullable();
      $table->string('match_key')->nullable();
      $table->boolean('is_correct')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
    });

    Schema::create('lms_assessments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
      $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->nullOnDelete();
      $table->string('title');
      $table->string('slug');
      $table->text('description')->nullable();
      $table->string('assessment_type', 40)->default('quiz')->index();
      $table->string('status', 40)->default('draft')->index();
      $table->decimal('pass_mark', 5, 2)->default(70);
      $table->unsignedInteger('time_limit_seconds')->nullable();
      $table->unsignedInteger('max_attempts')->nullable();
      $table->unsignedInteger('retake_cooldown_minutes')->nullable();
      $table->boolean('randomize_questions')->default(false);
      $table->unsignedInteger('random_question_count')->nullable();
      $table->boolean('negative_marking')->default(false);
      $table->decimal('negative_mark_value', 8, 2)->default(0);
      $table->boolean('show_immediate_result')->default(true);
      $table->boolean('allow_review')->default(true);
      $table->boolean('requires_instructor_grading')->default(false);
      $table->json('settings')->nullable();
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['course_id', 'slug']);
    });

    Schema::create('lms_assessment_questions', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('assessment_id')->constrained('lms_assessments')->cascadeOnDelete();
      $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
      $table->decimal('points', 8, 2)->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['assessment_id', 'question_id']);
    });

    Schema::create('lms_assessment_attempts', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('assessment_id')->constrained('lms_assessments')->cascadeOnDelete();
      $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->unsignedInteger('attempt_number')->default(1);
      $table->string('status', 40)->default('in_progress')->index();
      $table->timestamp('started_at')->nullable();
      $table->timestamp('submitted_at')->nullable();
      $table->timestamp('expires_at')->nullable();
      $table->timestamp('graded_at')->nullable();
      $table->decimal('score', 8, 2)->nullable();
      $table->decimal('max_score', 8, 2)->nullable();
      $table->decimal('percentage', 5, 2)->nullable();
      $table->string('grade')->nullable();
      $table->boolean('passed')->nullable();
      $table->text('remarks')->nullable();
      $table->json('question_order')->nullable();
      $table->foreignId('graded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['assessment_id', 'user_id']);
    });

    Schema::create('lms_attempt_answers', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('attempt_id')->constrained('lms_assessment_attempts')->cascadeOnDelete();
      $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
      $table->json('response_payload')->nullable();
      $table->boolean('is_correct')->nullable();
      $table->decimal('points_awarded', 8, 2)->nullable();
      $table->decimal('points_possible', 8, 2)->nullable();
      $table->boolean('needs_manual_grading')->default(false);
      $table->text('instructor_feedback')->nullable();
      $table->foreignId('graded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('graded_at')->nullable();
      $table->timestamps();

      $table->unique(['attempt_id', 'question_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_attempt_answers');
    Schema::dropIfExists('lms_assessment_attempts');
    Schema::dropIfExists('lms_assessment_questions');
    Schema::dropIfExists('lms_assessments');
    Schema::dropIfExists('lms_question_options');
    Schema::dropIfExists('lms_questions');
  }
};
