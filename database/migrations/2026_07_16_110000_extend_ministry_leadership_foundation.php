<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('cms_ministries', function (Blueprint $table): void {
      $table->text('mission')->nullable()->after('about');
      $table->text('vision')->nullable()->after('mission');
      $table->foreignId('logo_media_id')->nullable()->after('hero_media_id')->constrained('cms_media')->nullOnDelete();
      $table->foreignId('banner_media_id')->nullable()->after('logo_media_id')->constrained('cms_media')->nullOnDelete();
      $table->foreignId('cover_media_id')->nullable()->after('banner_media_id')->constrained('cms_media')->nullOnDelete();
      $table->string('visibility', 40)->default('public')->after('is_active');
      $table->string('operational_status', 40)->default('active')->after('visibility');
      $table->foreignId('leader_member_id')->nullable()->after('operational_status')->constrained('members')->nullOnDelete();
      $table->foreignId('assistant_leader_member_id')->nullable()->after('leader_member_id')->constrained('members')->nullOnDelete();
      $table->string('whatsapp_link')->nullable()->after('assistant_leader_member_id');
      $table->string('telegram_link')->nullable()->after('whatsapp_link');
      $table->string('signal_link')->nullable()->after('telegram_link');
      $table->json('country_availability')->nullable()->after('signal_link');
    });

    Schema::table('cms_leadership_profiles', function (Blueprint $table): void {
      $table->string('hierarchy_level', 60)->nullable()->index()->after('role');
      $table->string('state')->nullable()->after('location');
      $table->date('term_start')->nullable()->after('state');
      $table->date('term_end')->nullable()->after('term_start');
      $table->foreignId('member_id')->nullable()->after('ministry_id')->constrained('members')->nullOnDelete();
      $table->string('visibility', 40)->default('public')->after('is_active');
      $table->json('permissions')->nullable()->after('visibility');
    });
  }

  public function down(): void
  {
    Schema::table('cms_leadership_profiles', function (Blueprint $table): void {
      $table->dropForeign(['member_id']);
      $table->dropColumn(['hierarchy_level', 'state', 'term_start', 'term_end', 'member_id', 'visibility', 'permissions']);
    });

    Schema::table('cms_ministries', function (Blueprint $table): void {
      $table->dropForeign(['logo_media_id']);
      $table->dropForeign(['banner_media_id']);
      $table->dropForeign(['cover_media_id']);
      $table->dropForeign(['leader_member_id']);
      $table->dropForeign(['assistant_leader_member_id']);
      $table->dropColumn([
        'mission', 'vision', 'logo_media_id', 'banner_media_id', 'cover_media_id',
        'visibility', 'operational_status', 'leader_member_id', 'assistant_leader_member_id',
        'whatsapp_link', 'telegram_link', 'signal_link', 'country_availability',
      ]);
    });
  }
};
