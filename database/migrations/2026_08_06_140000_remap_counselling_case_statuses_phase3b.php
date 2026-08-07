<?php

declare(strict_types=1);

use App\Modules\Counselling\Enums\CaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3B — remap legacy counselling case statuses to the production workflow.
 */
return new class extends Migration
{
  public function up(): void
  {
    $map = [
      'pending' => CaseStatus::Submitted->value,
      'scheduled' => CaseStatus::AppointmentScheduled->value,
      'confirmed' => CaseStatus::Assigned->value,
      'session_1' => CaseStatus::InProgress->value,
      'session_2' => CaseStatus::InProgress->value,
      'session_3' => CaseStatus::InProgress->value,
      'on_hold' => CaseStatus::AwaitingResponse->value,
      'escalated' => CaseStatus::InProgress->value,
    ];

    foreach ($map as $from => $to) {
      DB::table('counselling_cases')->where('status', $from)->update(['status' => $to]);
    }
  }

  public function down(): void
  {
    DB::table('counselling_cases')->where('status', 'submitted')->update(['status' => 'pending']);
    DB::table('counselling_cases')->where('status', 'appointment_scheduled')->update(['status' => 'scheduled']);
    DB::table('counselling_cases')->where('status', 'assigned')->update(['status' => 'confirmed']);
    DB::table('counselling_cases')->where('status', 'in_progress')->update(['status' => 'session_1']);
    DB::table('counselling_cases')->where('status', 'awaiting_response')->update(['status' => 'on_hold']);
    DB::table('counselling_cases')->where('status', 'closed')->update(['status' => 'completed']);
  }
};
