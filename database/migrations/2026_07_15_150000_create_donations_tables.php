<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('donation_funds', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('slug')->unique();
      $table->string('name');
      $table->string('type'); // general|mission|projects|events|building|scholarship
      $table->text('description')->nullable();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('donation_bank_accounts', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('country_id')->constrained('cms_countries')->cascadeOnDelete();
      $table->string('bank_name');
      $table->string('account_name');
      $table->string('account_number');
      $table->string('routing_number')->nullable();
      $table->string('swift_code')->nullable();
      $table->string('iban')->nullable();
      $table->string('currency', 10)->default('USD');
      $table->text('instructions')->nullable();
      $table->boolean('is_active')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('country_payment_methods', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('country_id')->constrained('cms_countries')->cascadeOnDelete();
      $table->string('method'); // bank_account|card|flutterwave|paystack|stripe|paypal|offline|wire|crypto
      $table->string('provider_key')->nullable();
      $table->string('label')->nullable();
      $table->json('config')->nullable();
      $table->boolean('is_enabled')->default(true);
      $table->unsignedInteger('sort_order')->default(0);
      $table->timestamps();

      $table->unique(['country_id', 'method']);
    });

    Schema::create('payment_provider_configs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('provider'); // stripe|paystack|flutterwave|paypal|crypto
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->json('credentials')->nullable();
      $table->string('webhook_secret')->nullable();
      $table->boolean('is_live')->default(false);
      $table->boolean('is_enabled')->default(false);
      $table->timestamps();

      $table->unique(['provider', 'country_id']);
    });

    Schema::create('donations', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('reference')->unique();
      $table->foreignId('fund_id')->nullable()->constrained('donation_funds')->nullOnDelete();
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->foreignId('form_submission_id')->nullable()->constrained('cms_form_submissions')->nullOnDelete();
      $table->decimal('amount', 12, 2);
      $table->string('currency', 10)->default('USD');
      $table->string('status')->default('pending'); // pending|processing|succeeded|failed|refunded|cancelled
      $table->string('frequency')->default('one_time'); // one_time|monthly|quarterly|yearly
      $table->boolean('is_anonymous')->default(false);
      $table->boolean('needs_tax_receipt')->default(false);
      $table->string('donor_name')->nullable();
      $table->string('donor_email')->nullable();
      $table->string('donor_phone')->nullable();
      $table->string('payment_method');
      $table->string('provider')->nullable();
      $table->string('provider_intent_id')->nullable();
      $table->text('notes')->nullable();
      $table->json('metadata')->nullable();
      $table->timestamp('paid_at')->nullable();
      $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
      $table->softDeletes();

      $table->index(['status', 'created_at']);
      $table->index(['payment_method', 'provider']);
      $table->index(['country_id', 'fund_id']);
    });

    Schema::create('donation_payments', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('donation_id')->constrained('donations')->cascadeOnDelete();
      $table->string('provider');
      $table->string('provider_payment_id')->nullable();
      $table->decimal('amount', 12, 2);
      $table->string('currency', 10);
      $table->string('status')->default('pending');
      $table->json('raw_payload')->nullable();
      $table->timestamp('paid_at')->nullable();
      $table->timestamps();
    });

    Schema::create('donation_subscriptions', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('donation_id')->nullable()->constrained('donations')->nullOnDelete();
      $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
      $table->foreignId('fund_id')->nullable()->constrained('donation_funds')->nullOnDelete();
      $table->string('provider')->nullable();
      $table->string('provider_subscription_id')->nullable();
      $table->string('interval'); // monthly|quarterly|yearly
      $table->decimal('amount', 12, 2);
      $table->string('currency', 10);
      $table->string('status')->default('active'); // active|paused|cancelled|past_due
      $table->timestamp('next_charge_at')->nullable();
      $table->timestamp('cancelled_at')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });

    Schema::create('donation_receipts', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('donation_id')->constrained('donations')->cascadeOnDelete();
      $table->string('type'); // standard|tax
      $table->string('number')->unique();
      $table->unsignedSmallInteger('tax_year')->nullable();
      $table->foreignId('country_id')->nullable()->constrained('cms_countries')->nullOnDelete();
      $table->string('pdf_path')->nullable();
      $table->timestamp('issued_at')->nullable();
      $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
      $table->timestamps();
    });

    Schema::create('donation_audit_logs', function (Blueprint $table): void {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->string('event_type');
      $table->string('entity_type');
      $table->unsignedBigInteger('entity_id')->nullable();
      $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $table->json('old_values')->nullable();
      $table->json('new_values')->nullable();
      $table->string('ip_address', 45)->nullable();
      $table->text('user_agent')->nullable();
      $table->timestamps();

      $table->index(['entity_type', 'entity_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('donation_audit_logs');
    Schema::dropIfExists('donation_receipts');
    Schema::dropIfExists('donation_subscriptions');
    Schema::dropIfExists('donation_payments');
    Schema::dropIfExists('donations');
    Schema::dropIfExists('payment_provider_configs');
    Schema::dropIfExists('country_payment_methods');
    Schema::dropIfExists('donation_bank_accounts');
    Schema::dropIfExists('donation_funds');
  }
};
