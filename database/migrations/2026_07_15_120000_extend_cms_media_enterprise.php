<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('cms_media', function (Blueprint $table): void {
      $table->string('content_hash', 64)->nullable()->after('path')->index();
      $table->unsignedInteger('width')->nullable()->after('size');
      $table->unsignedInteger('height')->nullable()->after('width');
      $table->json('tags')->nullable()->after('metadata');
      $table->string('credits')->nullable()->after('tags');
      $table->string('copyright')->nullable()->after('credits');
      $table->decimal('focal_x', 5, 4)->nullable()->after('copyright');
      $table->decimal('focal_y', 5, 4)->nullable()->after('focal_x');
      $table->json('variants')->nullable()->after('focal_y');
      $table->boolean('is_optimized')->default(false)->after('variants');
    });
  }

  public function down(): void
  {
    Schema::table('cms_media', function (Blueprint $table): void {
      $table->dropColumn([
        'content_hash',
        'width',
        'height',
        'tags',
        'credits',
        'copyright',
        'focal_x',
        'focal_y',
        'variants',
        'is_optimized',
      ]);
    });
  }
};
