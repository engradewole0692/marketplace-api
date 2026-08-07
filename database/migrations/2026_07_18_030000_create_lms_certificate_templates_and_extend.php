<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_certificate_templates', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
      $table->string('name');
      $table->string('slug');
      $table->longText('html_body')->nullable();
      $table->foreignId('background_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('logo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('watermark_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('instructor_signature_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->foreignId('director_signature_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_active')->default(true)->index();
      $table->boolean('is_default')->default(false)->index();
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::table('lms_certificates', function (Blueprint $table): void {
      $table->foreignId('template_id')->nullable()->after('certificate_media_id')
        ->constrained('lms_certificate_templates')->nullOnDelete();
      $table->foreignId('issued_by_user_id')->nullable()->after('template_id')
        ->constrained('users')->nullOnDelete();
      $table->unsignedInteger('download_count')->default(0)->after('issued_by_user_id');
    });

    Schema::table('lms_courses', function (Blueprint $table): void {
      $table->boolean('certificate_enabled')->default(true)->after('is_recommended');
      $table->foreignId('certificate_template_id')->nullable()->after('certificate_enabled')
        ->constrained('lms_certificate_templates')->nullOnDelete();
      $table->boolean('certificate_requires_assessment_pass')->default(true)->after('certificate_template_id');
    });
  }

  public function down(): void
  {
    Schema::table('lms_courses', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('certificate_template_id');
      $table->dropColumn(['certificate_enabled', 'certificate_requires_assessment_pass']);
    });

    Schema::table('lms_certificates', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('template_id');
      $table->dropConstrainedForeignId('issued_by_user_id');
      $table->dropColumn('download_count');
    });

    Schema::dropIfExists('lms_certificate_templates');
  }
};
