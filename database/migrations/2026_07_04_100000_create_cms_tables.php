<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('cms_media_folders', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('parent_id')->nullable()->constrained('cms_media_folders')->nullOnDelete();
      $table->string('name');
      $table->string('slug')->unique();
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_media', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('folder_id')->nullable()->constrained('cms_media_folders')->nullOnDelete();
      $table->string('name');
      $table->string('file_name');
      $table->string('disk')->default('public');
      $table->string('path');
      $table->string('mime_type');
      $table->unsignedBigInteger('size');
      $table->string('alt_text')->nullable();
      $table->string('title')->nullable();
      $table->string('thumbnail_path')->nullable();
      $table->json('metadata')->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['folder_id', 'mime_type']);
    });

    Schema::create('cms_pages', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('title');
      $table->string('slug')->unique();
      $table->string('status')->default('draft');
      $table->string('hero_title')->nullable();
      $table->text('hero_subtitle')->nullable();
      $table->foreignId('hero_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->json('blocks')->nullable();
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamp('published_at')->nullable();
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['status', 'published_at']);
    });

    Schema::create('cms_page_versions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
      $table->unsignedInteger('version_number');
      $table->string('title');
      $table->string('status');
      $table->json('snapshot');
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->unique(['page_id', 'version_number']);
    });

    Schema::create('cms_page_sections', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('page_id')->nullable()->constrained('cms_pages')->cascadeOnDelete();
      $table->string('page_slug')->nullable();
      $table->string('section_key');
      $table->string('section_type');
      $table->string('title')->nullable();
      $table->json('content');
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['page_slug', 'section_key', 'is_active']);
    });

    Schema::create('cms_menus', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('location')->nullable();
      $table->boolean('is_active')->default(true);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_menu_items', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('menu_id')->constrained('cms_menus')->cascadeOnDelete();
      $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->nullOnDelete();
      $table->string('label');
      $table->string('url')->nullable();
      $table->string('route_name')->nullable();
      $table->string('icon')->nullable();
      $table->boolean('open_in_new_tab')->default(false);
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_seo', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('entity_type');
      $table->unsignedBigInteger('entity_id')->nullable();
      $table->string('path')->nullable()->unique();
      $table->string('meta_title')->nullable();
      $table->text('meta_description')->nullable();
      $table->string('canonical_url')->nullable();
      $table->string('og_title')->nullable();
      $table->text('og_description')->nullable();
      $table->foreignId('og_image_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('twitter_card')->nullable();
      $table->json('json_ld')->nullable();
      $table->boolean('no_index')->default(false);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['entity_type', 'entity_id']);
    });

    Schema::create('cms_countries', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('code', 8)->nullable();
      $table->string('region')->nullable();
      $table->string('flag_emoji')->nullable();
      $table->decimal('latitude', 10, 7)->nullable();
      $table->decimal('longitude', 10, 7)->nullable();
      $table->unsignedInteger('launched_year')->nullable();
      $table->text('summary')->nullable();
      $table->json('content')->nullable();
      $table->foreignId('hero_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_ministries', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('tagline')->nullable();
      $table->text('summary')->nullable();
      $table->longText('about')->nullable();
      $table->json('purposes')->nullable();
      $table->json('programs')->nullable();
      $table->json('content')->nullable();
      $table->foreignId('hero_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_leadership_profiles', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('role');
      $table->string('category')->default('global');
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->foreignId('ministry_id')->nullable()->constrained('cms_ministries')->nullOnDelete();
      $table->text('bio')->nullable();
      $table->foreignId('photo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->string('email')->nullable();
      $table->string('phone')->nullable();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_partners', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('tier')->nullable();
      $table->string('website_url')->nullable();
      $table->text('description')->nullable();
      $table->foreignId('logo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_featured')->default(false);
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_testimonials', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('author_name');
      $table->string('author_title')->nullable();
      $table->string('author_location')->nullable();
      $table->text('quote');
      $table->foreignId('photo_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
      $table->boolean('is_featured')->default(false);
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('cms_settings', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('group')->default('general');
      $table->string('key')->unique();
      $table->json('value')->nullable();
      $table->string('type')->default('string');
      $table->boolean('is_public')->default(false);
      $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
    });

    Schema::create('cms_form_submissions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('type');
      $table->string('status')->default('new');
      $table->json('payload');
      $table->string('submitter_name')->nullable();
      $table->string('submitter_email')->nullable();
      $table->string('source_ip', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamp('processed_at')->nullable();
      $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();
      $table->index(['type', 'status', 'created_at']);
    });

    Schema::create('cms_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('event_type');
      $table->string('entity_type');
      $table->unsignedBigInteger('entity_id')->nullable();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamps();
      $table->index(['entity_type', 'entity_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('cms_audit_logs');
    Schema::dropIfExists('cms_form_submissions');
    Schema::dropIfExists('cms_settings');
    Schema::dropIfExists('cms_testimonials');
    Schema::dropIfExists('cms_partners');
    Schema::dropIfExists('cms_leadership_profiles');
    Schema::dropIfExists('cms_ministries');
    Schema::dropIfExists('cms_countries');
    Schema::dropIfExists('cms_seo');
    Schema::dropIfExists('cms_menu_items');
    Schema::dropIfExists('cms_menus');
    Schema::dropIfExists('cms_page_sections');
    Schema::dropIfExists('cms_page_versions');
    Schema::dropIfExists('cms_pages');
    Schema::dropIfExists('cms_media');
    Schema::dropIfExists('cms_media_folders');
  }
};
