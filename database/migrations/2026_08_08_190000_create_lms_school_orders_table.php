<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_school_orders', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('order_number')->unique();
      $table->foreignId('school_enrollment_id')->constrained('lms_school_enrollments')->cascadeOnDelete();
      $table->foreignId('school_id')->constrained('lms_schools')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->decimal('list_amount', 12, 2)->default(0);
      $table->decimal('discount_amount', 12, 2)->default(0);
      $table->decimal('amount', 12, 2);
      $table->string('currency', 3)->default('USD');
      $table->string('learner_type', 40)->nullable();
      $table->string('status', 40)->default('pending')->index();
      $table->string('payment_method', 40)->nullable()->index();
      $table->foreignId('donation_id')->nullable()->constrained('donations')->nullOnDelete();
      $table->string('provider_intent_id')->nullable()->index();
      $table->timestamp('paid_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->json('pricing_snapshot')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_school_orders');
  }
};
