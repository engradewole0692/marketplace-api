<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_countries', function (Blueprint $table): void {
            $table->foreignId('primary_leader_id')
                ->nullable()
                ->after('hero_media_id')
                ->constrained('cms_leadership_profiles')
                ->nullOnDelete();
            $table->string('phone')->nullable()->after('primary_leader_id');
            $table->string('whatsapp_number')->nullable()->after('phone');
            $table->text('office_address')->nullable()->after('whatsapp_number');
            $table->string('office_hours')->nullable()->after('office_address');
        });
    }

    public function down(): void
    {
        Schema::table('cms_countries', function (Blueprint $table): void {
            $table->dropForeign(['primary_leader_id']);
            $table->dropColumn(['primary_leader_id', 'phone', 'whatsapp_number', 'office_address', 'office_hours']);
        });
    }
};
