<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies every payment in the shared donations ledger by purpose and records the pricing
 * decision on school orders. Additive only; existing rows are backfilled, never deleted.
 *
 * Indexes are explicitly named (under MySQL's 64-character limit) and each column and index is
 * guarded independently, so a partially applied run on MySQL (non-transactional DDL) can be
 * re-run safely.
 */
return new class extends Migration
{
  private const PURPOSE_MAP = [
    'school_order' => 'school_enrollment',
    'course_order' => 'course_purchase',
    'event_registration' => 'event_registration',
    'counselling_payment' => 'counselling',
  ];

  private const PURPOSE_STATUS_INDEX = 'don_purpose_status_idx';

  private const PRICING_CATEGORY_INDEX = 'lso_pricing_cat_idx';

  private const PROVIDER_TXN_INDEX = 'lso_provider_txn_idx';

  /** @var array<string, string> */
  private const LEGACY_INDEXES = [
    self::PURPOSE_STATUS_INDEX => 'donations_purpose_status_index',
    self::PRICING_CATEGORY_INDEX => 'lms_school_orders_pricing_category_index',
    self::PROVIDER_TXN_INDEX => 'lms_school_orders_provider_txn_index',
  ];

  public function up(): void
  {
    if (Schema::hasTable('donations')) {
      $this->addColumn('donations', 'payment_purpose', fn (Blueprint $table) => $table->string('payment_purpose', 40)->default('donation'), 'reference');
      $this->addIndex('donations', ['payment_purpose', 'status'], self::PURPOSE_STATUS_INDEX);
      $this->backfillPaymentPurpose();
    }

    if (Schema::hasTable('lms_school_orders')) {
      $this->addColumn('lms_school_orders', 'pricing_category', fn (Blueprint $table) => $table->string('pricing_category', 40)->nullable(), 'learner_type');
      $this->addColumn('lms_school_orders', 'membership_status', fn (Blueprint $table) => $table->string('membership_status', 40)->nullable(), 'pricing_category');
      $this->addColumn('lms_school_orders', 'provider_transaction_id', fn (Blueprint $table) => $table->string('provider_transaction_id', 191)->nullable(), 'provider_intent_id');
      $this->addIndex('lms_school_orders', ['pricing_category'], self::PRICING_CATEGORY_INDEX);
      $this->addIndex('lms_school_orders', ['provider_transaction_id'], self::PROVIDER_TXN_INDEX);

      if (Schema::hasColumn('lms_school_orders', 'learner_type')) {
        DB::table('lms_school_orders')->whereNull('pricing_category')->where('learner_type', 'member')
          ->update(['pricing_category' => 'full_member_subsidized']);
        DB::table('lms_school_orders')->whereNull('pricing_category')->where('learner_type', 'public')
          ->update(['pricing_category' => 'standard']);
      }
    }
  }

  public function down(): void
  {
    if (Schema::hasTable('lms_school_orders')) {
      $this->dropIndex('lms_school_orders', self::PROVIDER_TXN_INDEX);
      $this->dropIndex('lms_school_orders', self::PRICING_CATEGORY_INDEX);
      foreach (['provider_transaction_id', 'membership_status', 'pricing_category'] as $column) {
        if (Schema::hasColumn('lms_school_orders', $column)) {
          Schema::table('lms_school_orders', fn (Blueprint $table) => $table->dropColumn($column));
        }
      }
    }

    if (Schema::hasTable('donations')) {
      $this->dropIndex('donations', self::PURPOSE_STATUS_INDEX);
      if (Schema::hasColumn('donations', 'payment_purpose')) {
        Schema::table('donations', fn (Blueprint $table) => $table->dropColumn('payment_purpose'));
      }
    }
  }

  /**
   * @param  callable(Blueprint): \Illuminate\Database\Schema\ColumnDefinition  $define
   */
  private function addColumn(string $tableName, string $column, callable $define, string $after): void
  {
    if (Schema::hasColumn($tableName, $column)) {
      return;
    }

    $afterExists = Schema::hasColumn($tableName, $after);
    Schema::table($tableName, function (Blueprint $table) use ($define, $after, $afterExists): void {
      $definition = $define($table);
      if ($afterExists) {
        $definition->after($after);
      }
    });
  }

  /**
   * @param  list<string>  $columns
   */
  private function addIndex(string $tableName, array $columns, string $name): void
  {
    if (Schema::hasIndex($tableName, $name) || Schema::hasIndex($tableName, self::LEGACY_INDEXES[$name])) {
      return;
    }

    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
  }

  private function dropIndex(string $tableName, string $name): void
  {
    foreach ([$name, self::LEGACY_INDEXES[$name]] as $index) {
      if (Schema::hasIndex($tableName, $index)) {
        Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index));
      }
    }
  }

  private function backfillPaymentPurpose(): void
  {
    if (! Schema::hasColumn('donations', 'metadata')) {
      return;
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
  }
};
