<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: configurable event days, daily attendance, accommodation inventory/pairing,
 * service payments, and membership snapshot support.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'attendance_mode')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->string('attendance_mode', 20)->default('single')->after('attendance_required');
            });
        }

        if (! Schema::hasTable('event_days')) {
            Schema::create('event_days', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->unsignedSmallInteger('day_index');
                $table->date('date');
                $table->string('label', 120);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['event_id', 'date']);
                $table->unique(['event_id', 'day_index']);
                $table->index(['event_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('event_day_attendances')) {
            Schema::create('event_day_attendances', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('event_day_id')->constrained('event_days')->cascadeOnDelete();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->unsignedBigInteger('person_id')->nullable()->index();
                $table->unsignedBigInteger('member_id')->nullable()->index();
                $table->string('status', 32)->default('not_attended');
                $table->string('method', 32)->nullable();
                $table->timestamp('checked_in_at')->nullable();
                $table->timestamp('checked_out_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['registration_id', 'event_day_id'], 'event_day_att_reg_day_unique');
                $table->index(['event_id', 'event_day_id', 'status'], 'event_day_att_event_day_status');
            });
        }

        if (Schema::hasTable('event_check_ins') && ! Schema::hasColumn('event_check_ins', 'event_day_id')) {
            Schema::table('event_check_ins', function (Blueprint $table): void {
                $table->foreignId('event_day_id')->nullable()->after('event_session_id')->constrained('event_days')->nullOnDelete();
            });
        }

        if (Schema::hasTable('event_attendance_histories') && ! Schema::hasColumn('event_attendance_histories', 'event_day_id')) {
            Schema::table('event_attendance_histories', function (Blueprint $table): void {
                $table->foreignId('event_day_id')->nullable()->after('event_session_id')->constrained('event_days')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('event_accommodation_options')) {
            Schema::create('event_accommodation_options', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('location', 255)->nullable();
                $table->json('amenities')->nullable();
                $table->json('image_media_ids')->nullable();
                $table->string('occupancy_type', 32)->default('shared');
                $table->unsignedSmallInteger('capacity')->default(1);
                $table->unsignedInteger('unit_count')->default(1);
                $table->decimal('price', 12, 2)->default(0);
                $table->string('price_basis', 40)->default('per_person');
                $table->string('currency', 3)->default('USD');
                $table->string('status', 32)->default('available');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['event_id', 'status']);
            });
        }

        if (! Schema::hasTable('event_accommodation_pairings')) {
            Schema::create('event_accommodation_pairings', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('option_id')->nullable()->constrained('event_accommodation_options')->nullOnDelete();
                $table->string('status', 40)->default('requested');
                $table->unsignedBigInteger('requested_by_registration_id')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['event_id', 'status']);
            });
        }

        if (! Schema::hasTable('event_accommodation_pairing_members')) {
            Schema::create('event_accommodation_pairing_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pairing_id')->constrained('event_accommodation_pairings')->cascadeOnDelete();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->string('status', 32)->default('pending');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->timestamps();

                $table->unique(['pairing_id', 'registration_id'], 'event_acc_pair_member_unique');
            });
        }

        if (! Schema::hasTable('event_accommodation_allocations')) {
            Schema::create('event_accommodation_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('option_id')->constrained('event_accommodation_options')->cascadeOnDelete();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->unsignedBigInteger('pairing_id')->nullable();
                $table->unsignedSmallInteger('spaces')->default(1);
                $table->string('status', 32)->default('pending');
                $table->date('check_in_date')->nullable();
                $table->date('check_out_date')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->json('details')->nullable();
                $table->timestamps();

                $table->unique('registration_id', 'event_acc_alloc_reg_unique');
                $table->index(['option_id', 'status']);
            });
        }

        if (Schema::hasTable('event_reg_services') && ! Schema::hasColumn('event_reg_services', 'option_id')) {
            Schema::table('event_reg_services', function (Blueprint $table): void {
                $table->unsignedBigInteger('option_id')->nullable()->after('type');
                $table->unsignedBigInteger('allocation_id')->nullable()->after('option_id');
                $table->index('option_id');
            });
        }

        if (Schema::hasTable('event_registration_payments') && ! Schema::hasColumn('event_registration_payments', 'service_id')) {
            Schema::table('event_registration_payments', function (Blueprint $table): void {
                $table->unsignedBigInteger('service_id')->nullable()->after('registration_id');
                $table->string('purpose', 40)->nullable()->after('service_id');
                $table->index('service_id');
            });
        }

        $this->backfillEventDays();
    }

    public function down(): void
    {
        if (Schema::hasTable('event_registration_payments') && Schema::hasColumn('event_registration_payments', 'service_id')) {
            Schema::table('event_registration_payments', function (Blueprint $table): void {
                $table->dropColumn(['service_id', 'purpose']);
            });
        }

        if (Schema::hasTable('event_reg_services') && Schema::hasColumn('event_reg_services', 'option_id')) {
            Schema::table('event_reg_services', function (Blueprint $table): void {
                $table->dropColumn(['option_id', 'allocation_id']);
            });
        }

        Schema::dropIfExists('event_accommodation_allocations');
        Schema::dropIfExists('event_accommodation_pairing_members');
        Schema::dropIfExists('event_accommodation_pairings');
        Schema::dropIfExists('event_accommodation_options');

        if (Schema::hasTable('event_check_ins') && Schema::hasColumn('event_check_ins', 'event_day_id')) {
            Schema::table('event_check_ins', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('event_day_id');
            });
        }

        if (Schema::hasTable('event_attendance_histories') && Schema::hasColumn('event_attendance_histories', 'event_day_id')) {
            Schema::table('event_attendance_histories', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('event_day_id');
            });
        }

        Schema::dropIfExists('event_day_attendances');
        Schema::dropIfExists('event_days');

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'attendance_mode')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->dropColumn('attendance_mode');
            });
        }
    }

    private function backfillEventDays(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasTable('event_days')) {
            return;
        }

        $events = DB::table('events')->select('id', 'starts_at', 'ends_at', 'attendance_mode', 'timezone')->orderBy('id')->get();
        foreach ($events as $event) {
            $exists = DB::table('event_days')->where('event_id', $event->id)->exists();
            if ($exists || $event->starts_at === null) {
                continue;
            }

            $tz = is_string($event->timezone) && $event->timezone !== '' ? $event->timezone : 'UTC';
            try {
                $start = \Illuminate\Support\Carbon::parse($event->starts_at, $tz)->timezone($tz)->startOfDay();
                $end = $event->ends_at
                    ? \Illuminate\Support\Carbon::parse($event->ends_at, $tz)->timezone($tz)->startOfDay()
                    : $start->copy();
            } catch (\Throwable) {
                continue;
            }

            if ($end->lt($start)) {
                $end = $start->copy();
            }

            $mode = $event->attendance_mode ?: 'single';
            if ($mode !== 'daily' && $end->gt($start)) {
                DB::table('events')->where('id', $event->id)->update(['attendance_mode' => 'daily']);
                $mode = 'daily';
            }

            $cursor = $start->copy();
            $index = 1;
            $max = 31;
            while ($cursor->lte($end) && $index <= $max) {
                if ($mode === 'single' && $index > 1) {
                    break;
                }
                $now = now();
                DB::table('event_days')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'event_id' => $event->id,
                    'day_index' => $index,
                    'date' => $cursor->toDateString(),
                    'label' => 'Day '.$index,
                    'starts_at' => $cursor->copy()->setTimeFrom(\Illuminate\Support\Carbon::parse($event->starts_at)),
                    'ends_at' => $event->ends_at
                        ? $cursor->copy()->setTimeFrom(\Illuminate\Support\Carbon::parse($event->ends_at))
                        : $cursor->copy()->endOfDay(),
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $cursor->addDay();
                $index++;
            }
        }
    }
};
