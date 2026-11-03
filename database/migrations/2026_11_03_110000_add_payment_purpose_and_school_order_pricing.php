<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies every payment in the shared donations ledger by purpose and records the pricing
 * decision on school orders. Additive only; existing rows are backfilled, never deleted.
 */
return new class extends Migration
{
  private const PURPOSE_MAP = [
    'school_order' => 'school_enrollment',
    'course_order' => 'course_purchase',
    'event_registration' => 'event_registration',
    'counselling_payment' => 'counselling',
  ];

  public function up(): void
  {
    if (! Schema::hasColumn('donations', 'payment_purpose')) {
      Schema::table('donations', function (Blueprint $table): void {
        $table->string('payment_purpose', 40)->default('donation')->after('reference');
        $table->index(['payment_purpose', 'status'], 'donations_purpose_status_index');
      });
    }

    DB::table('donations')
      ->whereNotNull('metadata')
      ->select(['id', 'metadata'])
      ->orderBy('id')
      ->chunkById(500, function ($rows): void {
        foreach ($rows as $row) {
          $meta = json_decode((string) $row->metadata, true);
          $purpose = self::PURPOSE_MAP[is_array($meta) ? (string) ($meta['purpose'] ?? '') : ''] ?? null;
          if ($purpose !== null) {
            DB::table('donations')->where('id', $row->id)->update(['payment_purpose' => $purpose]);
          }
        }
      });

    Schema::table('lms_school_orders', function (Blueprint $table): void {
      if (! Schema::hasColumn('lms_school_orders', 'pricing_category')) {
        $table->string('pricing_category', 40)->nullable()->after('learner_type');
        $table->index('pricing_category', 'lms_school_orders_pricing_category_index');
      }
      if (! Schema::hasColumn('lms_school_orders', 'membership_status')) {
        $table->string('membership_status', 40)->nullable()->after('pricing_category');
      }
      if (! Schema::hasColumn('lms_school_orders', 'provider_transaction_id')) {
        $table->string('provider_transaction_id', 191)->nullable()->after('provider_intent_id');
        $table->index('provider_transaction_id', 'lms_school_orders_provider_txn_index');
      }
    });

    DB::table('lms_school_orders')->whereNull('pricing_category')->where('learner_type', 'member')
      ->update(['pricing_category' => 'full_member_subsidized']);
    DB::table('lms_school_orders')->whereNull('pricing_category')->where('learner_type', 'public')
      ->update(['pricing_category' => 'standard']);
  }

  public function down(): void
  {
    Schema::table('lms_school_orders', function (Blueprint $table): void {
      if (Schema::hasColumn('lms_school_orders', 'provider_transaction_id')) {
        $table->dropIndex('lms_school_orders_provider_txn_index');
        $table->dropColumn('provider_transaction_id');
      }
      if (Schema::hasColumn('lms_school_orders', 'membership_status')) {
        $table->dropColumn('membership_status');
      }
      if (Schema::hasColumn('lms_school_orders', 'pricing_category')) {
        $table->dropIndex('lms_school_orders_pricing_category_index');
        $table->dropColumn('pricing_category');
      }
    });

    if (Schema::hasColumn('donations', 'payment_purpose')) {
      Schema::table('donations', function (Blueprint $table): void {
        $table->dropIndex('donations_purpose_status_index');
        $table->dropColumn('payment_purpose');
      });
    }
  }
};
