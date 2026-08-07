<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('iam_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('event_type', 80)->index();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('subject_type', 120)->nullable()->index();
      $table->unsignedBigInteger('subject_id')->nullable()->index();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->json('metadata')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamp('created_at')->useCurrent();

      $table->index(['subject_type', 'subject_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('iam_audit_logs');
  }
};
