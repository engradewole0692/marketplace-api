<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_course_imports', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
      $table->string('filename');
      $table->string('status', 32)->default('pending');
      $table->boolean('publish_after_import')->default(false);
      $table->boolean('create_missing_schools')->default(false);
      $table->boolean('create_missing_categories')->default(false);
      $table->boolean('create_missing_program_modules')->default(false);
      $table->json('summary')->nullable();
      $table->json('report')->nullable();
      $table->json('settings')->nullable();
      $table->timestamps();

      $table->index(['status', 'created_at']);
      $table->index('admin_user_id');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_course_imports');
  }
};
