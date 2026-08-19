<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('business_name');
            $table->string('business_location')->nullable();
            $table->string('business_industry')->nullable();
            $table->text('business_description')->nullable();
            $table->string('business_stage')->nullable();
            $table->text('main_challenges')->nullable();
            $table->text('business_goals')->nullable();
            $table->string('website_social')->nullable();
            $table->string('preferred_contact')->default('email');
            $table->text('additional_info')->nullable();
            $table->json('extra_answers')->nullable();
            $table->string('status', 40)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('platform_conversations')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email');
            $table->index('created_at');
        });

        Schema::create('business_review_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_review_id')->constrained('business_reviews')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_review_notes');
        Schema::dropIfExists('business_reviews');
    }
};
