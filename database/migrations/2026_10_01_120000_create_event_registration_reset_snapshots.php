<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit name stays well under MySQL's 64-character identifier limit.
     * Laravel's default would be:
     * event_registration_reset_snapshots_event_id_reset_type_restored_at_index (72).
     */
    private const LOOKUP_INDEX = 'errs_event_type_restored_idx';

    public function up(): void
    {
        if (! Schema::hasTable('event_registration_reset_snapshots')) {
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

                $table->index(['event_id', 'reset_type', 'restored_at'], self::LOOKUP_INDEX);
            });

            return;
        }

        // Recover a partial MySQL apply: CREATE TABLE (and FKs) can succeed
        // before ALTER TABLE ADD INDEX fails on identifier length.
        Schema::table('event_registration_reset_snapshots', function (Blueprint $table): void {
            if (! Schema::hasIndex('event_registration_reset_snapshots', self::LOOKUP_INDEX)
                && ! Schema::hasIndex('event_registration_reset_snapshots', ['event_id', 'reset_type', 'restored_at'])) {
                $table->index(['event_id', 'reset_type', 'restored_at'], self::LOOKUP_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_reset_snapshots');
    }
};
