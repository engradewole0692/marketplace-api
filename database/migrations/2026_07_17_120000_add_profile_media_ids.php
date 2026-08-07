<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('users', function (Blueprint $table): void {
      if (! Schema::hasColumn('users', 'avatar_media_id')) {
        $table->foreignId('avatar_media_id')
          ->nullable()
          ->after('avatar')
          ->constrained('cms_media')
          ->nullOnDelete();
      }
    });

    Schema::table('members', function (Blueprint $table): void {
      if (! Schema::hasColumn('members', 'photo_media_id')) {
        $table->foreignId('photo_media_id')
          ->nullable()
          ->after('photo_path')
          ->constrained('cms_media')
          ->nullOnDelete();
      }
    });
  }

  public function down(): void
  {
    Schema::table('users', function (Blueprint $table): void {
      if (Schema::hasColumn('users', 'avatar_media_id')) {
        $table->dropConstrainedForeignId('avatar_media_id');
      }
    });

    Schema::table('members', function (Blueprint $table): void {
      if (Schema::hasColumn('members', 'photo_media_id')) {
        $table->dropConstrainedForeignId('photo_media_id');
      }
    });
  }
};
