<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_accommodation_pairing_members')) {
            Schema::table('event_accommodation_pairing_members', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_accommodation_pairing_members', 'check_in_date')) {
                    $table->date('check_in_date')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_pairing_members', 'check_out_date')) {
                    $table->date('check_out_date')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_pairing_members', 'actual_nights')) {
                    $table->unsignedSmallInteger('actual_nights')->nullable();
                }
            });
        }

        if (Schema::hasTable('event_accommodation_pairings')) {
            Schema::table('event_accommodation_pairings', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_accommodation_pairings', 'billable_check_in_date')) {
                    $table->date('billable_check_in_date')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_pairings', 'billable_check_out_date')) {
                    $table->date('billable_check_out_date')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_pairings', 'billable_nights')) {
                    $table->unsignedSmallInteger('billable_nights')->nullable();
                }
            });
        }

        if (Schema::hasTable('event_transport_options')) {
            Schema::table('event_transport_options', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_transport_options', 'origin')) {
                    $table->string('origin', 160)->nullable();
                }
                if (! Schema::hasColumn('event_transport_options', 'destination')) {
                    $table->string('destination', 160)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Additive integration columns are retained.
    }
};
