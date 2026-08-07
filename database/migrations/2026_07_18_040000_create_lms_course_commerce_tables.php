<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('lms_course_orders', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('order_number')->unique();
      $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
      $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->decimal('list_amount', 12, 2)->default(0);
      $table->decimal('discount_amount', 12, 2)->default(0);
      $table->decimal('amount', 12, 2);
      $table->string('currency', 3)->default('USD');
      $table->string('coupon_code')->nullable();
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

    Schema::create('lms_course_invoices', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('order_id')->constrained('lms_course_orders')->cascadeOnDelete();
      $table->string('invoice_number')->unique();
      $table->string('type', 40)->default('invoice')->index();
      $table->string('pdf_path')->nullable();
      $table->timestamp('issued_at')->nullable();
      $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('metadata')->nullable();
      $table->timestamps();
    });

    Schema::create('lms_course_refunds', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('order_id')->constrained('lms_course_orders')->cascadeOnDelete();
      $table->foreignId('donation_id')->nullable()->constrained('donations')->nullOnDelete();
      $table->decimal('amount', 12, 2);
      $table->string('currency', 3)->default('USD');
      $table->string('status', 40)->default('pending')->index();
      $table->string('reason')->nullable();
      $table->text('notes')->nullable();
      $table->boolean('gateway_refunded')->default(false);
      $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamp('processed_at')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('lms_course_refunds');
    Schema::dropIfExists('lms_course_invoices');
    Schema::dropIfExists('lms_course_orders');
  }
};
