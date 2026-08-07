<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('authentication_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
      $table->string('event_type', 50);
      $table->string('email')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamp('created_at')->useCurrent();

      $table->index(['user_id', 'event_type']);
      $table->index('created_at');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('authentication_audit_logs');
  }
};
