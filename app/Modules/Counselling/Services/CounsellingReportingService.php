<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\ClientType;
use App\Modules\Counselling\Enums\PaymentStatus;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingPayment;
use Illuminate\Support\Carbon;

final class CounsellingReportingService implements ServiceContract
{
  /** @var list<string> */
  private const OPEN_STATUSES = [
    CaseStatus::Submitted->value,
    CaseStatus::PendingReview->value,
    CaseStatus::UnderReview->value,
    CaseStatus::AwaitingClient->value,
    CaseStatus::Assigned->value,
    CaseStatus::AppointmentScheduled->value,
    CaseStatus::InProgress->value,
    CaseStatus::FollowUpRequired->value,
    CaseStatus::WaitingPayment->value,
    CaseStatus::PaymentConfirmed->value,
  ];

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function dashboard(array $filters = []): array
  {
    [$from, $to] = $this->dateRange($filters);

    $casesQuery = CounsellingCase::query()
      ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

    $paymentsQuery = CounsellingPayment::query()
      ->where('status', PaymentStatus::Paid->value)
      ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('paid_at', '<=', $to));

    $pending = (clone $casesQuery)->whereIn('status', [
      CaseStatus::Submitted->value,
      CaseStatus::PendingReview->value,
    ])->count();
    $underReview = (clone $casesQuery)->where('status', CaseStatus::UnderReview->value)->count();
    $awaitingClient = (clone $casesQuery)->where('status', CaseStatus::AwaitingClient->value)->count();
    $awaitingPayment = (clone $casesQuery)->where('status', CaseStatus::WaitingPayment->value)->count();
    $assigned = (clone $casesQuery)->whereNotNull('counsellor_id')->whereIn('status', self::OPEN_STATUSES)->count();
    $completed = (clone $casesQuery)->whereIn('status', [
      CaseStatus::Completed->value,
      CaseStatus::Closed->value,
    ])->count();
    $revenue = round((float) (clone $paymentsQuery)->sum('amount'), 2);

    $freeCases = (clone $casesQuery)->where(function ($q): void {
      $q->where('metadata->payment_decision', 'free')
        ->orWhere('metadata->payment_decision', 'waived')
        ->orWhereDoesntHave('payments')
        ->orWhereHas('payments', fn ($inner) => $inner->where('amount', '<=', 0));
    })->count();
    $paidCases = (clone $casesQuery)->whereHas('payments', fn ($q) => $q->where('amount', '>', 0)->where('status', PaymentStatus::Paid->value))->count();

    $memberCases = (clone $casesQuery)->where('client_type', ClientType::Member->value)->count();
    $visitorCases = (clone $casesQuery)->where('client_type', ClientType::Visitor->value)->count();

    $todayAppointments = \App\Modules\Counselling\Models\CounsellingAppointment::query()
      ->whereDate('starts_at', now()->toDateString())
      ->whereNotIn('status', ['cancelled', 'rescheduled'])
      ->count();

    $activeCounsellors = \App\Modules\Counselling\Models\Counsellor::query()
      ->where('is_active', true)
      ->count();

    $avgResponseHours = $this->averageResponseHours($from, $to);

    $popularCategories = CounsellingCategory::query()
      ->withCount([
        'cases as cases_count' => fn ($q) => $q
          ->when($from, fn ($inner) => $inner->where('created_at', '>=', $from))
          ->when($to, fn ($inner) => $inner->where('created_at', '<=', $to)),
      ])
      ->orderByDesc('cases_count')
      ->limit(10)
      ->get(['id', 'uuid', 'name', 'slug'])
      ->map(fn (CounsellingCategory $category) => [
        'id' => $category->uuid,
        'name' => $category->name,
        'slug' => $category->slug,
        'cases_count' => (int) $category->cases_count,
      ])
      ->values()
      ->all();

    $casesByCounsellor = \App\Modules\Counselling\Models\Counsellor::query()
      ->withCount([
        'cases as cases_count' => fn ($q) => $q
          ->when($from, fn ($inner) => $inner->where('created_at', '>=', $from))
          ->when($to, fn ($inner) => $inner->where('created_at', '<=', $to)),
      ])
      ->orderByDesc('cases_count')
      ->limit(20)
      ->get(['id', 'uuid', 'display_name'])
      ->map(fn ($counsellor) => [
        'counsellor_id' => $counsellor->uuid,
        'counsellor_name' => $counsellor->display_name,
        'cases_count' => (int) $counsellor->cases_count,
      ])
      ->values()
      ->all();

    return [
      'generated_at' => now()->toIso8601String(),
      'filters' => [
        'from' => $from?->toDateString(),
        'to' => $to?->toDateString(),
      ],
      'pending' => $pending,
      'under_review' => $underReview,
      'awaiting_client' => $awaitingClient,
      'awaiting_payment' => $awaitingPayment,
      'assigned' => $assigned,
      'completed' => $completed,
      'revenue' => $revenue,
      'today_appointments' => $todayAppointments,
      'active_counsellors' => $activeCounsellors,
      'average_response_hours' => $avgResponseHours,
      'free_vs_paid' => [
        'free' => $freeCases,
        'paid' => $paidCases,
      ],
      'member_vs_visitor' => [
        'member' => $memberCases,
        'visitor' => $visitorCases,
      ],
      'popular_categories' => $popularCategories,
      'cases_by_counsellor' => $casesByCounsellor,
      'cases_total' => (clone $casesQuery)->count(),
      'payments_paid' => (clone $paymentsQuery)->count(),
    ];
  }

  private function averageResponseHours(?Carbon $from, ?Carbon $to): ?float
  {
    $cases = CounsellingCase::query()
      ->whereNotNull('assigned_at')
      ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
      ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
      ->get(['created_at', 'assigned_at']);

    if ($cases->isEmpty()) {
      return null;
    }

    $hours = $cases->map(fn ($case) => $case->created_at->diffInMinutes($case->assigned_at) / 60)->average();

    return $hours !== null ? round((float) $hours, 2) : null;
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{0: ?Carbon, 1: ?Carbon}
   */
  private function dateRange(array $filters): array
  {
    $from = ! empty($filters['from']) ? Carbon::parse((string) $filters['from'])->startOfDay() : null;
    $to = ! empty($filters['to']) ? Carbon::parse((string) $filters['to'])->endOfDay() : null;

    return [$from, $to];
  }
}
