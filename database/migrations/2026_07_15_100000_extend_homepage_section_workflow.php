<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('cms_page_sections', function (Blueprint $table): void {
      $table->string('status')->default('published')->after('is_active');
      $table->json('draft_content')->nullable()->after('content');
      $table->timestamp('published_at')->nullable()->after('status');
    });

    Schema::create('cms_page_section_versions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('section_id')->constrained('cms_page_sections')->cascadeOnDelete();
      $table->unsignedInteger('version_number');
      $table->string('status')->default('published');
      $table->json('content');
      $table->string('change_summary')->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();

      $table->unique(['section_id', 'version_number']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('cms_page_section_versions');

    Schema::table('cms_page_sections', function (Blueprint $table): void {
      $table->dropColumn(['status', 'draft_content', 'published_at']);
    });
  }
};
