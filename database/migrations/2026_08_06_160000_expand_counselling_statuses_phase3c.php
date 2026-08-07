<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3C — expand counselling case statuses for production workflow labels.
 */
return new class extends Migration
{
  public function up(): void
  {
    $map = [
      'awaiting_response' => 'awaiting_client',
      'on_hold' => 'awaiting_client',
    ];

    foreach ($map as $from => $to) {
      DB::table('counselling_cases')->where('status', $from)->update(['status' => $to]);
    }
  }

  public function down(): void
  {
    DB::table('counselling_cases')->where('status', 'awaiting_client')->update(['status' => 'awaiting_response']);
  }
};
