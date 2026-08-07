<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('permissions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('name');
      $table->string('slug')->unique();
      $table->string('module', 80)->index();
      $table->string('group', 80)->nullable();
      $table->text('description')->nullable();
      $table->boolean('is_system')->default(true);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('permission_role', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
      $table->foreignId('role_id')->constrained()->cascadeOnDelete();
      $table->timestamps();

      $table->unique(['permission_id', 'role_id']);
    });

    Schema::create('permission_user', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();
      $table->timestamps();

      $table->unique(['permission_id', 'user_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('permission_user');
    Schema::dropIfExists('permission_role');
    Schema::dropIfExists('permissions');
  }
};
