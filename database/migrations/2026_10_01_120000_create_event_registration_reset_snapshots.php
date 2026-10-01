<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_reset_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reset_type', 40);
            $table->unsignedInteger('registration_count')->default(0);
            $table->json('affected_counts')->nullable();
            $table->longText('payload');
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'reset_type', 'restored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_reset_snapshots');
    }
};
