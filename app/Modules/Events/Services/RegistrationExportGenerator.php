<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventExportJob;
use App\Modules\Events\Models\EventRegService;
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

      case 'attendance_matrix':
        $event = $eventId ? \App\Modules\Events\Models\Event::query()->find($eventId) : null;
        if ($event === null) {
          return [['error'], [['error' => 'event_id is required']]];
        }
        $report = app(EventOpsDashboardService::class)->attendanceReport($event, $filters);
        $dayHeaders = array_map(fn ($day) => $day['label'], $report['days']);
        $headers = array_merge(['name', 'membership'], $dayHeaders, ['attendance']);
        $rows = array_map(function (array $row) use ($report): array {
          $out = [
            'name' => $row['name'],
            'membership' => $row['membership'],
          ];
          foreach ($report['days'] as $day) {
            $out[$day['label']] = ! empty($row['days'][$day['id']]['attended']) ? 'Y' : '';
          }
          $out['attendance'] = $row['attendance'];

          return $out;
        }, $report['rows']);

        return [$headers, $rows];

      case 'accommodation':
        $query = EventRegService::query()
          ->where('type', 'accommodation')
          ->with(['registration.event', 'registration.person', 'option']);
        if ($eventId !== null) {
          $query->whereHas('registration', fn ($q) => $q->where('event_id', $eventId));
        }
        $headers = ['event', 'registration_number', 'name', 'accommodation', 'occupancy', 'group', 'sharing_members', 'nights', 'amount', 'payment_status', 'allocation_status', 'country', 'state', 'membership', 'category'];
        $rows = $query->get()->map(function (EventRegService $service): array {
          $details = is_array($service->details) ? $service->details : [];
          $registration = $service->registration;
          $profile = is_array($registration?->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
          $payment = $registration?->payments()->where('purpose', 'accommodation')->latest('id')->first();
          $allocation = $registration?->accommodationAllocation;

          return [
            'event' => $registration?->event?->title,
            'registration_number' => $registration?->registration_number,
            'name' => $registration?->contactName(),
            'accommodation' => $service->option?->name ?? ($details['option_name'] ?? null),
            'occupancy' => $details['occupancy_type'] ?? null,
            'group' => $details['progress'] ?? null,
            'sharing_members' => is_array($details['members'] ?? null) ? implode(', ', $details['members']) : ($details['requested_option'] ?? null),
            'nights' => $details['nights'] ?? null,
            'amount' => $details['person_total'] ?? ($payment?->amount),
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
            'allocation_status' => $allocation?->status,
            'country' => $registration?->person?->country?->name,
            'state' => $registration?->person?->region,
            'membership' => $registration?->member_id ? 'member' : 'visitor',
            'category' => $profile['participant_category'] ?? $profile['membership_status'] ?? null,
          ];
        })->all();

        return [$headers, $rows];

      case 'logistics':
        $query = \App\Modules\Events\Models\EventTransportTrip::query()
          ->with(['registration.event', 'registration.person', 'option']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'registration_number', 'name', 'route', 'date', 'time', 'passengers', 'amount', 'vehicle', 'status', 'payment_status'];
        $rows = $query->get()->map(function ($trip): array {
          $payment = $trip->payment_id
            ? EventRegistrationPayment::query()->find($trip->payment_id)
            : $trip->registration?->payments()->where('purpose', 'transport')->latest('id')->first();

          return [
            'event' => $trip->registration?->event?->title,
            'registration_number' => $trip->registration?->registration_number,
            'name' => $trip->registration?->contactName(),
            'route' => $trip->route,
            'date' => $trip->trip_date?->toDateString(),
            'time' => $trip->trip_time,
            'passengers' => $trip->passengers,
            'amount' => (string) $trip->amount,
            'vehicle' => $trip->assigned_vehicle ?? $trip->option?->vehicle_name,
            'status' => $trip->status,
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
          ];
        })->all();

        return [$headers, $rows];

      case 'travel':
        $query = \App\Modules\Events\Models\EventTravelRequest::query()
          ->with(['registration.event', 'registration.person']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = ['event', 'registration_number', 'name', 'origin', 'destination', 'departure_date', 'return_date', 'travel_class', 'airline_preference', 'status', 'quote', 'payment_status', 'booking_status'];
        $rows = $query->get()->map(function ($travel): array {
          $payment = $travel->payment_id
            ? EventRegistrationPayment::query()->find($travel->payment_id)
            : $travel->registration?->payments()->where('purpose', 'travel')->latest('id')->first();

          return [
            'event' => $travel->registration?->event?->title,
            'registration_number' => $travel->registration?->registration_number,
            'name' => $travel->registration?->contactName(),
            'origin' => $travel->origin,
            'destination' => $travel->destination,
            'departure_date' => $travel->departure_date?->toDateString(),
            'return_date' => $travel->return_date?->toDateString(),
            'travel_class' => $travel->travel_class,
            'airline_preference' => $travel->airline_preference,
            'status' => $travel->status,
            'quote' => $travel->quote_amount !== null ? (string) $travel->quote_amount : null,
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
            'booking_status' => $travel->status,
          ];
        })->all();

        return [$headers, $rows];

      case 'payments':
        $query = EventRegistrationPayment::query()->with(['event', 'registration']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        if (! empty($filters['payment_status'])) {
          $query->where('status', $filters['payment_status']);
        }
        $headers = ['event', 'registration_number', 'name', 'amount', 'currency', 'status', 'purpose', 'paid_at'];
        $rows = $query->get()->map(fn (EventRegistrationPayment $p): array => [
          'event' => $p->event?->title,
          'registration_number' => $p->registration?->registration_number,
          'name' => $p->registration?->contactName(),
          'amount' => (string) $p->amount,
          'currency' => $p->currency,
          'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
          'purpose' => $p->purpose ?? 'registration',
          'paid_at' => $p->paid_at?->toDateTimeString(),
        ])->all();

        return [$headers, $rows];

      case 'members_visitors':
        $event = $eventId ? \App\Modules\Events\Models\Event::query()->find($eventId) : null;
        if ($event === null) {
          return [['error'], [['error' => 'event_id is required']]];
        }
        $report = app(EventOpsDashboardService::class)->attendanceReport($event, $filters);
        $headers = ['name', 'registration_number', 'membership', 'membership_type', 'country', 'attendance'];
        $rows = array_map(fn (array $row): array => [
          'name' => $row['name'],
          'registration_number' => $row['registration_number'],
          'membership' => $row['membership'],
          'membership_type' => $row['membership_type'],
          'country' => $row['country'],
          'attendance' => $row['attendance'],
        ], $report['rows']);

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

        return [RegistrantExportBuilder::headers($eventId), $registrationRows];
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
