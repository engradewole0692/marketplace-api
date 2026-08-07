<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventReportSnapshot;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Support\RegistrantExportBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    return EventReportSnapshot::query()
      ->with('event')
      ->orderByDesc('generated_at')
      ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function generate(array $filters, User $actor): EventReportSnapshot
  {
    $registrationQuery = RegistrantExportBuilder::filteredQuery(
      ! empty($filters['event_id']) ? (int) $filters['event_id'] : null,
      $filters,
    );
    $registrations = (clone $registrationQuery)->get();

    $byStatus = $registrations
      ->groupBy(fn ($r) => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status)
      ->map->count()
      ->all();

    $byMinistry = $registrations
      ->groupBy(fn ($r) => $r->event?->ministry?->name ?? 'unknown')
      ->map->count()
      ->all();

    $byEvent = $registrations
      ->groupBy(fn ($r) => $r->event?->title ?? 'unknown')
      ->map->count()
      ->all();

    $attendanceRecords = $this->attendanceQuery($filters)->get();
    $byAttendance = $attendanceRecords
      ->groupBy(fn ($a) => $a->status instanceof \BackedEnum ? $a->status->value : (string) $a->status)
      ->map->count()
      ->all();

    $total = $registrations->count();
    $checkedIn = $registrations->where('status', 'checked_in')->count();
    $checkedOut = $registrations->where('status', 'attended')->count();
    $approved = $registrations->where('status', 'approved')->count();
    $pending = $registrations->whereNotIn('status', ['checked_in', 'attended', 'cancelled', 'declined'])->count();
    $attendanceRate = $total > 0 ? round((($checkedIn + $checkedOut) / $total) * 100, 1) : 0.0;

    $registrationTrend = $registrations
      ->groupBy(fn ($registration) => $registration->created_at?->toDateString() ?? 'unknown')
      ->map->count()
      ->sortKeys()
      ->all();

    $certificateQuery = EventCertificateIssuance::query();
    if (! empty($filters['event_id'])) {
      $certificateQuery->where('event_id', $filters['event_id']);
    }
    $certificateCount = (clone $certificateQuery)->count();

    $volunteerQuery = EventVolunteerAssignment::query();
    if (! empty($filters['event_id'])) {
      $volunteerQuery->where('event_id', $filters['event_id']);
    }
    $volunteerAssignments = (clone $volunteerQuery)->count();
    $volunteerByStatus = (clone $volunteerQuery)
      ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
      ->groupBy('status')
      ->pluck('total', 'status')
      ->all();

    $paymentQuery = EventRegistrationPayment::query();
    if (! empty($filters['event_id'])) {
      $paymentQuery->where('event_id', $filters['event_id']);
    }
    $revenueTotal = (clone $paymentQuery)->where('status', PaymentStatus::Paid->value)->sum('amount');

    $metrics = [
      'registrations_total' => $total,
      'approved_total' => $approved,
      'attendance_total' => $attendanceRecords->count(),
      'checked_in_total' => $checkedIn,
      'checked_out_total' => $checkedOut,
      'pending_total' => $pending,
      'attendance_percentage' => $attendanceRate,
      'attendance_rate' => $attendanceRate,
      'registration_trend' => $registrationTrend,
      'by_registration_status' => $byStatus,
      'by_ministry' => $byMinistry,
      'by_event' => $byEvent,
      'by_attendance_status' => $byAttendance,
      'certificate_count' => $certificateCount,
      'volunteer_metrics' => [
        'total' => $volunteerAssignments,
        'by_status' => $volunteerByStatus,
      ],
      'revenue_total' => (float) $revenueTotal,
    ];

    return EventReportSnapshot::query()->create([
      'event_id' => $filters['event_id'] ?? null,
      'report_type' => $filters['report_type'] ?? 'event_summary',
      'filters' => $filters,
      'metrics' => $metrics,
      'generated_by_user_id' => $actor->id,
      'generated_at' => now(),
    ]);
  }

  public function download(EventReportSnapshot $snapshot): StreamedResponse
  {
    $snapshot->load('generator');
    $metrics = $snapshot->metrics ?? [];
    $filters = is_array($snapshot->filters) ? $snapshot->filters : [];
    $rows = RegistrantExportBuilder::buildRows($snapshot->event_id, $filters);
    $context = RegistrantExportBuilder::buildContext($snapshot->event_id, $filters, $snapshot->generator, count($rows));
    $headers = RegistrantExportBuilder::headers();
    $filename = sprintf('report-%s-%s.csv', Str::slug($snapshot->report_type), $snapshot->generated_at?->format('Ymd-His') ?? now()->format('Ymd-His'));

    $lines = array_merge(
      $this->contextCsvLines($context),
      [''],
      $this->metricsToCsvLines($metrics),
      [''],
      [implode(',', $headers)],
      array_map(
        static fn (array $row) => implode(',', array_map(
          static fn ($value) => '"'.str_replace('"', '""', (string) ($value ?? '')).'"',
          array_map(static fn ($header) => $row[$header] ?? '', $headers),
        )),
        $rows,
      ),
    );

    return response()->streamDownload(static function () use ($lines): void {
      echo implode("\n", $lines);
    }, $filename, ['Content-Type' => 'text/csv']);
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function attendanceQuery(array $filters): Builder
  {
    $query = EventAttendanceHistory::query()->whereHas('registration');

    if (! empty($filters['event_id'])) {
      $query->where('event_id', $filters['event_id']);
    }

    if (! empty($filters['attendance_status'])) {
      $query->where('status', $filters['attendance_status']);
    }

    return $query;
  }

  /**
   * @param  array<string, mixed>  $metrics
   * @return list<string>
   */
  private function metricsToCsvLines(array $metrics): array
  {
    $lines = ['metric,value'];

    foreach ($metrics as $key => $value) {
      if (is_array($value)) {
        foreach ($value as $subKey => $subValue) {
          $lines[] = sprintf('%s.%s,%s', $key, $subKey, $subValue);
        }
        continue;
      }

      $lines[] = sprintf('%s,%s', $key, $value);
    }

    return $lines;
  }

  /**
   * @param  array<string, mixed>  $context
   * @return list<string>
   */
  private function contextCsvLines(array $context): array
  {
    return [
      'organization,'.$context['organization_name'],
      'event,'.($context['event_title'] ?? 'All events'),
      'event_date,'.($context['event_date'] ?? ''),
      'venue,'.($context['venue'] ?? ''),
      'generated_at,'.$context['generated_at'],
      'generated_by,'.($context['generated_by'] ?? 'System'),
    ];
  }
}
