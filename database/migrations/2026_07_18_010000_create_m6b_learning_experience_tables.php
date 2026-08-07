<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('lms_lesson_progress', function (Blueprint $table): void {
      $table->unsignedInteger('time_spent_seconds')->default(0)->after('last_position_seconds');
    });

    Schema::table('lms_bookmarks', function (Blueprint $table): void {
      $table->unsignedInteger('position_seconds')->nullable()->after('note');
      $table->string('label')->nullable()->after('position_seconds');
    });

    Schema::create('lms_lesson_notes', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
      $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
      $table->string('title')->nullable();
      $table->longText('body');
      $table->unsignedInteger('position_seconds')->nullable();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['user_id', 'lesson_id']);
    });

    Schema::create('lms_learning_activities', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
      $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
      $table->foreignId('lesson_id')->nullable()->constrained('lms_lessons')->nullOnDelete();
      $table->string('event_type', 80)->index();
      $table->string('title');
      $table->text('description')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamp('occurred_at')->index();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_learning_activities');
    Schema::dropIfExists('lms_lesson_notes');

    Schema::table('lms_bookmarks', function (Blueprint $table): void {
      $table->dropColumn(['position_seconds', 'label']);
    });

    Schema::table('lms_lesson_progress', function (Blueprint $table): void {
      $table->dropColumn('time_spent_seconds');
    });
  }
};
