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
    Schema::create('regions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug');
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
      $table->softDeletes();

      $table->unique(['country_id', 'slug']);
      $table->index(['country_id', 'is_active']);
    });

    $validCountryIds = DB::table('cms_countries')->pluck('id');
    $validMinistryIds = DB::table('cms_ministries')->pluck('id');

    DB::table('members')
      ->whereNotNull('country_id')
      ->whereNotIn('country_id', $validCountryIds)
      ->update(['country_id' => null]);

    DB::table('members')
      ->whereNotNull('ministry_id')
      ->whereNotIn('ministry_id', $validMinistryIds)
      ->update(['ministry_id' => null]);

    DB::table('members')
      ->whereNotNull('region_id')
      ->update(['region_id' => null]);

    Schema::table('members', function (Blueprint $table): void {
      $table->foreign('country_id')->references('id')->on('cms_countries')->nullOnDelete();
      $table->foreign('ministry_id')->references('id')->on('cms_ministries')->nullOnDelete();
      $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
    });
  }

  public function down(): void
  {
    Schema::table('members', function (Blueprint $table): void {
      $table->dropForeign(['country_id']);
      $table->dropForeign(['ministry_id']);
      $table->dropForeign(['region_id']);
    });

    Schema::dropIfExists('regions');
  }
};
