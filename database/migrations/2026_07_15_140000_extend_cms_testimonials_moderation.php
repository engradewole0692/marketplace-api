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
    Schema::table('cms_testimonials', function (Blueprint $table): void {
      $table->string('status')->default('approved')->after('quote');
      $table->string('category')->nullable()->after('status');
      $table->boolean('is_anonymous')->default(false)->after('category');
      $table->string('submitter_type')->default('guest')->after('is_anonymous');
      $table->string('submitter_email')->nullable()->after('submitter_type');
      $table->string('submitter_phone')->nullable()->after('submitter_email');
      $table->foreignId('member_id')->nullable()->after('submitter_phone')->constrained('members')->nullOnDelete();
      $table->foreignId('video_media_id')->nullable()->after('photo_media_id')->constrained('cms_media')->nullOnDelete();
      $table->boolean('show_on_homepage')->default(false)->after('is_featured');
      $table->boolean('show_on_page')->default(true)->after('show_on_homepage');
      $table->text('rejection_reason')->nullable()->after('show_on_page');
      $table->foreignId('moderated_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
      $table->timestamp('moderated_at')->nullable()->after('moderated_by');
      $table->foreignId('source_submission_id')->nullable()->after('moderated_at')->constrained('cms_form_submissions')->nullOnDelete();
      $table->index(['status', 'is_active']);
      $table->index(['show_on_homepage', 'show_on_page']);
      $table->index('category');
    });

    // Existing curated rows stay visible.
    DB::table('cms_testimonials')->update([
      'status' => 'approved',
      'show_on_page' => true,
      'show_on_homepage' => DB::raw('is_featured'),
    ]);
  }

  public function down(): void
  {
    Schema::table('cms_testimonials', function (Blueprint $table): void {
      $table->dropConstrainedForeignId('member_id');
      $table->dropConstrainedForeignId('video_media_id');
      $table->dropConstrainedForeignId('moderated_by');
      $table->dropConstrainedForeignId('source_submission_id');
      $table->dropIndex('cms_testimonials_status_is_active_index');
      $table->dropIndex('cms_testimonials_show_on_homepage_show_on_page_index');
      $table->dropIndex('cms_testimonials_category_index');
      $table->dropColumn([
        'status',
        'category',
        'is_anonymous',
        'submitter_type',
        'submitter_email',
        'submitter_phone',
        'show_on_homepage',
        'show_on_page',
        'rejection_reason',
        'moderated_at',
      ]);
    });
  }
};
