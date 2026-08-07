<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('cms_pages', function (Blueprint $table): void {
      $table->timestamp('scheduled_at')->nullable()->after('published_at');
    });

    Schema::table('cms_page_versions', function (Blueprint $table): void {
      $table->string('change_summary')->nullable()->after('snapshot');
    });

    Schema::table('cms_leadership_profiles', function (Blueprint $table): void {
      $table->string('location')->nullable()->after('role');
      $table->json('social_links')->nullable()->after('phone');
    });

    Schema::table('cms_ministries', function (Blueprint $table): void {
      $table->string('icon')->nullable()->after('slug');
      $table->string('color')->nullable()->after('icon');
    });

    Schema::table('cms_partners', function (Blueprint $table): void {
      $table->foreignId('country_id')->nullable()->after('slug')->constrained('cms_countries')->nullOnDelete();
      $table->string('donation_url')->nullable()->after('website_url');
    });

    Schema::table('cms_seo', function (Blueprint $table): void {
      $table->string('meta_keywords')->nullable()->after('meta_description');
      $table->string('robots')->nullable()->after('no_index');
    });

    Schema::table('cms_form_submissions', function (Blueprint $table): void {
      $table->foreignId('assigned_to')->nullable()->after('processed_by')->constrained('users')->nullOnDelete();
    });

    Schema::create('cms_catalog_items', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('type');
      $table->string('title');
      $table->string('slug');
      $table->text('summary')->nullable();
      $table->longText('body')->nullable();
      $table->json('metadata')->nullable();
      $table->string('category')->nullable();
      $table->json('tags')->nullable();
      $table->foreignId('featured_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('status')->default('published');
      $table->boolean('is_active')->default(true);
      $table->boolean('is_featured')->default(false);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamp('published_at')->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->unique(['type', 'slug']);
      $table->index(['type', 'status', 'is_active']);
    });

    Schema::create('cms_form_submission_notes', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('submission_id')->constrained('cms_form_submissions')->cascadeOnDelete();
      $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
      $table->text('body');
      $table->timestamps();
    });

    Schema::create('cms_admin_notifications', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->string('type');
      $table->string('title');
      $table->text('message');
      $table->json('data')->nullable();
      $table->timestamp('read_at')->nullable();
      $table->timestamps();
      $table->index(['user_id', 'read_at']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('cms_admin_notifications');
    Schema::dropIfExists('cms_form_submission_notes');
    Schema::dropIfExists('cms_catalog_items');

    Schema::table('cms_form_submissions', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('assigned_to');
    });

    Schema::table('cms_seo', function (Blueprint $table): void {
      $table->dropColumn(['meta_keywords', 'robots']);
    });

    Schema::table('cms_partners', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('country_id');
      $table->dropColumn('donation_url');
    });

    Schema::table('cms_ministries', function (Blueprint $table): void {
      $table->dropColumn(['icon', 'color']);
    });

    Schema::table('cms_leadership_profiles', function (Blueprint $table): void {
      $table->dropColumn(['location', 'social_links']);
    });

    Schema::table('cms_page_versions', function (Blueprint $table): void {
      $table->dropColumn('change_summary');
    });

    Schema::table('cms_pages', function (Blueprint $table): void {
      $table->dropColumn('scheduled_at');
    });
  }
};
