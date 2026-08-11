<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('communication_idempotency_keys', function (Blueprint $table): void {
      $table->id();
      $table->string('idempotency_key')->unique();
      $table->string('event_key', 128);
      $table->timestamps();

      $table->index(['event_key', 'created_at']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('communication_idempotency_keys');
  }
};
