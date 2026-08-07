<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventExportJob;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Models\EventVolunteerAssignment;
use App\Modules\Events\Models\Speaker;
use App\Modules\Events\Support\RegistrantExportBuilder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RegistrationExportGenerator
{
  /**
   * @return array{path: string, disk: string, filename: string}
   */
  public function generate(EventExportJob $job): array
  {
    $filters = $job->filters ?? [];
    $type = $job->export_type ?: 'registrations';
    $format = $job->format ?: 'csv';

    [$headers, $rows] = $this->buildRowsForType($type, $job->event_id, $filters);
    $context = RegistrantExportBuilder::buildContext($job->event_id, $filters, $job->requester, count($rows));

    $filename = $this->filename($job);
    $relativePath = 'exports/'.$filename;

    if ($format === 'xlsx') {
      $this->writeXlsx($headers, $rows, $context, $relativePath);
    } else {
      $this->writeCsv($headers, $rows, $context, $relativePath);
    }

    return [
      'path' => $relativePath,
      'disk' => 'public',
      'filename' => $filename,
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{0: list<string>, 1: list<array<string, mixed>>}
   */
  private function buildRowsForType(string $type, ?int $eventId, array $filters): array
  {
    switch ($type) {
      case 'attendance':
        $query = EventAttendanceHistory::query()->with(['event', 'registration', 'member']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        if (! empty($filters['attendance_status'])) {
          $query->where('status', $filters['attendance_status']);
        }
        $headers = ['event', 'registration_number', 'name', 'status', 'source', 'occurred_at'];
        $rows = $query->get()->map(fn (EventAttendanceHistory $entry): array => [
          'event' => $entry->event?->title,
          'registration_number' => $entry->registration?->registration_number,
          'name' => $entry->registration?->contactName(),
          'status' => $entry->status instanceof \BackedEnum ? $entry->status->value : $entry->status,
          'source' => $entry->source,
          'occurred_at' => $entry->occurred_at?->toDateTimeString(),
        ])->all();

        return [$headers, $rows];

      case 'certificates':
        $query = EventCertificateIssuance::query()->with(['event', 'registration']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'certificate_number', 'verification_code', 'recipient', 'status', 'issued_at'];
        $rows = $query->get()->map(fn (EventCertificateIssuance $c): array => [
          'event' => $c->event?->title,
          'certificate_number' => $c->certificate_number,
          'verification_code' => $c->verification_code,
          'recipient' => $c->registration?->contactName(),
          'status' => $c->status instanceof \BackedEnum ? $c->status->value : $c->status,
          'issued_at' => $c->issued_at?->toDateTimeString(),
        ])->all();

        return [$headers, $rows];

      case 'volunteers':
        $query = EventVolunteerAssignment::query()->with(['event', 'role', 'registration', 'member']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'role', 'volunteer', 'status', 'shift_starts_at', 'shift_ends_at', 'performance_score'];
        $rows = $query->get()->map(fn (EventVolunteerAssignment $a): array => [
          'event' => $a->event?->title,
          'role' => $a->role?->name,
          'volunteer' => $a->registration?->contactName() ?? $a->member?->fullName(),
          'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status,
          'shift_starts_at' => $a->shift_starts_at?->toDateTimeString(),
          'shift_ends_at' => $a->shift_ends_at?->toDateTimeString(),
          'performance_score' => $a->performance_score,
        ])->all();

        return [$headers, $rows];

      case 'speakers':
        $query = Speaker::query();
        $rows = $query->get()->map(fn (Speaker $s): array => [
          'name' => $s->name,
          'title' => $s->title,
          'organization' => $s->organization,
          'email' => $s->email,
          'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
        ])->all();

        return [['name', 'title', 'organization', 'email', 'status'], $rows];

      case 'sessions':
        $query = EventSession::query()->with(['speaker', 'event']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'title', 'speaker', 'track', 'room', 'starts_at', 'ends_at'];
        $rows = $query->get()->map(fn (EventSession $s): array => [
          'event' => $s->event?->title,
          'title' => $s->title,
          'speaker' => $s->speaker?->name,
          'track' => $s->track,
          'room' => $s->room,
          'starts_at' => $s->starts_at?->toDateTimeString(),
          'ends_at' => $s->ends_at?->toDateTimeString(),
        ])->all();

        return [$headers, $rows];

      case 'revenue':
        $query = EventRegistrationPayment::query()->with(['event', 'registration']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'registration_number', 'amount', 'currency', 'status', 'payment_method', 'paid_at'];
        $rows = $query->get()->map(fn (EventRegistrationPayment $p): array => [
          'event' => $p->event?->title,
          'registration_number' => $p->registration?->registration_number,
          'amount' => (string) $p->amount,
          'currency' => $p->currency,
          'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
          'payment_method' => $p->payment_method instanceof \BackedEnum ? $p->payment_method->value : $p->payment_method,
          'paid_at' => $p->paid_at?->toDateTimeString(),
        ])->all();

        return [$headers, $rows];

      case 'registrations':
      default:
        $registrationRows = RegistrantExportBuilder::buildRows($eventId, $filters);

        return [RegistrantExportBuilder::headers(), $registrationRows];
    }
  }

  /**
   * @param  list<string>  $headers
   * @param  list<array<string, mixed>>  $rows
   * @param  array<string, mixed>  $context
   */
  private function writeCsv(array $headers, array $rows, array $context, string $relativePath): void
  {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
      throw new \RuntimeException('Unable to open temporary stream for CSV export.');
    }

    foreach ($this->contextLines($context) as $line) {
      fputcsv($handle, $line);
    }
    fputcsv($handle, []);

    fputcsv($handle, $headers);
    foreach ($rows as $row) {
      fputcsv($handle, array_map(static fn ($h) => (string) ($row[$h] ?? ''), $headers));
    }

    rewind($handle);
    Storage::disk('public')->put($relativePath, stream_get_contents($handle) ?: '');
    fclose($handle);
  }

  /**
   * @param  list<string>  $headers
   * @param  list<array<string, mixed>>  $rows
   * @param  array<string, mixed>  $context
   */
  private function writeXlsx(array $headers, array $rows, array $context, string $relativePath): void
  {
    if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
      $this->writeCsv($headers, $rows, $context, $relativePath);

      return;
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $rowIndex = 1;
    foreach ($this->contextLines($context) as $line) {
      $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($line[0] ?? ''));
      $sheet->setCellValueByColumnAndRow(2, $rowIndex, (string) ($line[1] ?? ''));
      $rowIndex++;
    }
    $rowIndex++;

    foreach ($headers as $i => $header) {
      $sheet->setCellValueByColumnAndRow($i + 1, $rowIndex, $header);
    }
    $rowIndex++;

    foreach ($rows as $row) {
      foreach ($headers as $i => $header) {
        $sheet->setCellValueByColumnAndRow($i + 1, $rowIndex, (string) ($row[$header] ?? ''));
      }
      $rowIndex++;
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $temp = tempnam(sys_get_temp_dir(), 'xlsx');
    $writer->save($temp);
    $contents = file_get_contents($temp) ?: '';
    @unlink($temp);
    Storage::disk('public')->put($relativePath, $contents);
  }

  /**
   * @param  array<string, mixed>  $context
   * @return list<array{0: string, 1: string|null}>
   */
  private function contextLines(array $context): array
  {
    return [
      ['Organization', (string) ($context['organization_name'] ?? '')],
      ['Event', (string) ($context['event_title'] ?? 'All events')],
      ['Event Date', (string) ($context['event_date'] ?? '—')],
      ['Venue', (string) ($context['venue'] ?? '—')],
      ['Generated At', (string) ($context['generated_at'] ?? '')],
      ['Generated By', (string) ($context['generated_by'] ?? 'System')],
      ['Records', (string) ($context['record_count'] ?? 0)],
    ];
  }

  private function filename(EventExportJob $job): string
  {
    $slug = Str::slug($job->export_type ?: 'registrations');

    return sprintf('%s-%s.%s', $slug, now()->format('Ymd-His'), $job->format);
  }
}
