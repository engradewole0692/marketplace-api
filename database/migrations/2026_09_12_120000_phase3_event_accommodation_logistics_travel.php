<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table): void {
                if (! Schema::hasColumn('events', 'accommodation_enabled')) {
                    $table->boolean('accommodation_enabled')->default(false)->after('certificate_enabled');
                }
                if (! Schema::hasColumn('events', 'transport_enabled')) {
                    $table->boolean('transport_enabled')->default(false)->after('accommodation_enabled');
                }
                if (! Schema::hasColumn('events', 'travel_assistance_enabled')) {
                    $table->boolean('travel_assistance_enabled')->default(false)->after('transport_enabled');
                }
            });
        }

        if (Schema::hasTable('event_accommodation_options')) {
            Schema::table('event_accommodation_options', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_accommodation_options', 'address')) {
                    $table->string('address', 255)->nullable()->after('location');
                }
                if (! Schema::hasColumn('event_accommodation_options', 'distance_from_venue')) {
                    $table->string('distance_from_venue', 120)->nullable()->after('address');
                }
                if (! Schema::hasColumn('event_accommodation_options', 'room_type')) {
                    $table->string('room_type', 80)->nullable()->after('distance_from_venue');
                }
                if (! Schema::hasColumn('event_accommodation_options', 'check_in_info')) {
                    $table->text('check_in_info')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'check_out_info')) {
                    $table->text('check_out_info')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'min_nights')) {
                    $table->unsignedTinyInteger('min_nights')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'max_nights')) {
                    $table->unsignedTinyInteger('max_nights')->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'price_per_night')) {
                    $table->decimal('price_per_night', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'private_price')) {
                    $table->decimal('private_price', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'shared_price')) {
                    $table->decimal('shared_price', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('event_accommodation_options', 'require_full_occupancy')) {
                    $table->boolean('require_full_occupancy')->default(true);
                }
                if (! Schema::hasColumn('event_accommodation_options', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
            });
        }

        if (Schema::hasTable('event_accommodation_pairings')) {
            Schema::table('event_accommodation_pairings', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_accommodation_pairings', 'check_in_date')) {
                    $table->date('check_in_date')->nullable()->after('option_id');
                }
                if (! Schema::hasColumn('event_accommodation_pairings', 'check_out_date')) {
                    $table->date('check_out_date')->nullable()->after('check_in_date');
                }
                if (! Schema::hasColumn('event_accommodation_pairings', 'nights')) {
                    $table->unsignedSmallInteger('nights')->nullable()->after('check_out_date');
                }
            });
        }

        if (! Schema::hasTable('event_transport_options')) {
            Schema::create('event_transport_options', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('route', 160)->nullable();
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('price_basis', 40)->default('per_trip');
                $table->string('currency', 3)->nullable();
                $table->date('service_date')->nullable();
                $table->json('time_windows')->nullable();
                $table->string('vehicle_type', 80)->nullable();
                $table->string('vehicle_name', 120)->nullable();
                $table->string('make_model', 160)->nullable();
                $table->unsignedSmallInteger('passenger_capacity')->nullable();
                $table->unsignedSmallInteger('luggage_capacity')->nullable();
                $table->json('image_media_ids')->nullable();
                $table->text('vehicle_details')->nullable();
                $table->text('pickup_instructions')->nullable();
                $table->text('dropoff_instructions')->nullable();
                $table->string('status', 32)->default('available');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['event_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('event_transport_trips')) {
            Schema::create('event_transport_trips', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->foreignId('option_id')->nullable()->constrained('event_transport_options')->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('event_reg_services')->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('event_registration_payments')->nullOnDelete();
                $table->string('route', 160)->nullable();
                $table->string('pickup_location', 255)->nullable();
                $table->string('dropoff_location', 255)->nullable();
                $table->date('trip_date')->nullable();
                $table->string('trip_time', 20)->nullable();
                $table->unsignedSmallInteger('passengers')->default(1);
                $table->string('luggage', 120)->nullable();
                $table->text('special_requirements')->nullable();
                $table->json('flight_info')->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 3)->nullable();
                $table->string('status', 32)->default('requested');
                $table->string('assigned_vehicle', 160)->nullable();
                $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->json('details')->nullable();
                $table->timestamps();
                $table->index(['event_id', 'status']);
                $table->index(['registration_id', 'status']);
            });
        }

        if (! Schema::hasTable('event_travel_requests')) {
            Schema::create('event_travel_requests', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('registration_id')->unique()->constrained('event_registrations')->cascadeOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('event_reg_services')->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('event_registration_payments')->nullOnDelete();
                $table->string('trip_type', 32)->default('return');
                $table->string('origin', 160)->nullable();
                $table->string('destination', 160)->nullable();
                $table->date('departure_date')->nullable();
                $table->string('preferred_departure_time', 40)->nullable();
                $table->date('return_date')->nullable();
                $table->string('preferred_return_time', 40)->nullable();
                $table->string('airline_preference', 120)->nullable();
                $table->string('travel_class', 40)->nullable();
                $table->unsignedSmallInteger('passengers')->default(1);
                $table->json('passenger_names')->nullable();
                $table->text('notes')->nullable();
                $table->decimal('quote_amount', 12, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->string('status', 40)->default('requested');
                $table->timestamp('quoted_at')->nullable();
                $table->timestamp('booked_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->json('details')->nullable();
                $table->timestamps();
                $table->index(['event_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_travel_requests');
        Schema::dropIfExists('event_transport_trips');
        Schema::dropIfExists('event_transport_options');
    }
};
