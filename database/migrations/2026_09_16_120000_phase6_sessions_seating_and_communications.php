<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'main_hall_capacity')) {
                $table->unsignedInteger('main_hall_capacity')->nullable()->after('capacity');
            }
            if (! Schema::hasColumn('events', 'overflow_capacity')) {
                $table->unsignedInteger('overflow_capacity')->nullable()->after('main_hall_capacity');
            }
            if (! Schema::hasColumn('events', 'seating_policy')) {
                $table->string('seating_policy', 40)->default('main_then_overflow')->after('overflow_capacity');
            }
            if (! Schema::hasColumn('events', 'default_grace_before_minutes')) {
                $table->unsignedSmallInteger('default_grace_before_minutes')->default(15)->after('seating_policy');
            }
            if (! Schema::hasColumn('events', 'default_grace_after_minutes')) {
                $table->unsignedSmallInteger('default_grace_after_minutes')->default(15)->after('default_grace_before_minutes');
            }
            if (! Schema::hasColumn('events', 'checkout_enabled')) {
                $table->boolean('checkout_enabled')->default(true)->after('check_in_enabled');
            }
        });

        Schema::table('event_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_sessions', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }
            if (! Schema::hasColumn('event_sessions', 'grace_before_minutes')) {
                $table->unsignedSmallInteger('grace_before_minutes')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('event_sessions', 'grace_after_minutes')) {
                $table->unsignedSmallInteger('grace_after_minutes')->nullable()->after('grace_before_minutes');
            }
            if (! Schema::hasColumn('event_sessions', 'session_number')) {
                $table->unsignedSmallInteger('session_number')->nullable()->after('sort_order');
            }
        });

        Schema::table('persons', function (Blueprint $table): void {
            if (! Schema::hasColumn('persons', 'phone_country_code')) {
                $table->string('phone_country_code', 8)->nullable()->after('phone');
            }
        });

        Schema::table('event_staff_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_staff_assignments', 'department')) {
                $table->string('department', 80)->nullable()->after('staff_role');
            }
        });

        Schema::table('event_transport_options', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_transport_options', 'public_route_key')) {
                $table->string('public_route_key', 60)->nullable()->after('route');
            }
        });

        Schema::table('event_day_attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_day_attendances', 'seating_area')) {
                $table->string('seating_area', 40)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('event_day_attendances', 'counts_toward_seating')) {
                $table->boolean('counts_toward_seating')->default(true)->after('seating_area');
            }
            if (! Schema::hasColumn('event_day_attendances', 'capacity_overridden')) {
                $table->boolean('capacity_overridden')->default(false)->after('counts_toward_seating');
            }
            if (! Schema::hasColumn('event_day_attendances', 'override_reason')) {
                $table->string('override_reason', 255)->nullable()->after('capacity_overridden');
            }
        });

        Schema::table('event_check_ins', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_check_ins', 'seating_area')) {
                $table->string('seating_area', 40)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('event_check_ins', 'counts_toward_seating')) {
                $table->boolean('counts_toward_seating')->nullable()->after('seating_area');
            }
        });

        if (! Schema::hasTable('event_registration_sessions')) {
            Schema::create('event_registration_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('event_registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->foreignId('event_session_id')->constrained('event_sessions')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['event_registration_id', 'event_session_id'], 'event_reg_sessions_unique');
            });
        }

        if (! Schema::hasTable('event_session_attendances')) {
            Schema::create('event_session_attendances', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('event_session_id')->constrained('event_sessions')->cascadeOnDelete();
                $table->foreignId('event_day_id')->nullable()->constrained('event_days')->nullOnDelete();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
                $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
                $table->string('status', 40)->default('checked_in');
                $table->string('method', 40)->nullable();
                $table->string('seating_area', 40)->nullable();
                $table->boolean('counts_toward_seating')->default(true);
                $table->boolean('capacity_overridden')->default(false);
                $table->string('override_reason', 255)->nullable();
                $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('checked_in_at')->nullable();
                $table->timestamp('checked_out_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['registration_id', 'event_session_id'], 'event_session_att_unique');
                $table->index(['event_session_id', 'seating_area', 'status'], 'event_session_att_occupancy');
            });
        }

        if (! Schema::hasTable('event_seat_classifications')) {
            Schema::create('event_seat_classifications', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->string('scope', 40);
                $table->string('match_key', 80);
                $table->string('label', 120)->nullable();
                $table->boolean('counts_toward_seating')->default(true);
                $table->timestamps();
                $table->unique(['event_id', 'scope', 'match_key'], 'event_seat_class_unique');
            });
        }

        if (! Schema::hasTable('communication_outbound_messages')) {
            Schema::create('communication_outbound_messages', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('channel', 20);
                $table->string('to', 191);
                $table->string('status', 20)->default('queued');
                $table->string('provider', 40)->nullable();
                $table->string('provider_message_id', 120)->nullable();
                $table->text('body')->nullable();
                $table->string('subject', 255)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();
                $table->index(['channel', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_outbound_messages');
        Schema::dropIfExists('event_seat_classifications');
        Schema::dropIfExists('event_session_attendances');
        Schema::dropIfExists('event_registration_sessions');

        Schema::table('event_check_ins', function (Blueprint $table): void {
            if (Schema::hasColumn('event_check_ins', 'counts_toward_seating')) {
                $table->dropColumn('counts_toward_seating');
            }
            if (Schema::hasColumn('event_check_ins', 'seating_area')) {
                $table->dropColumn('seating_area');
            }
        });

        Schema::table('event_day_attendances', function (Blueprint $table): void {
            foreach (['override_reason', 'capacity_overridden', 'counts_toward_seating', 'seating_area'] as $column) {
                if (Schema::hasColumn('event_day_attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('event_transport_options', function (Blueprint $table): void {
            if (Schema::hasColumn('event_transport_options', 'public_route_key')) {
                $table->dropColumn('public_route_key');
            }
        });

        Schema::table('event_staff_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('event_staff_assignments', 'department')) {
                $table->dropColumn('department');
            }
        });

        Schema::table('persons', function (Blueprint $table): void {
            if (Schema::hasColumn('persons', 'phone_country_code')) {
                $table->dropColumn('phone_country_code');
            }
        });

        Schema::table('event_sessions', function (Blueprint $table): void {
            foreach (['session_number', 'grace_after_minutes', 'grace_before_minutes', 'is_active'] as $column) {
                if (Schema::hasColumn('event_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('events', function (Blueprint $table): void {
            foreach ([
                'checkout_enabled',
                'default_grace_after_minutes',
                'default_grace_before_minutes',
                'seating_policy',
                'overflow_capacity',
                'main_hall_capacity',
            ] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
