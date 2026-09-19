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
use App\Modules\Events\Support\MembershipClassification;
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
  public function rowsForType(string $type, ?int $eventId, array $filters): array
  {
    return $this->buildRowsForType($type, $eventId, $filters);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{0: list<string>, 1: list<array<string, mixed>>}
   */
  private function buildRowsForType(string $type, ?int $eventId, array $filters): array
  {
    switch ($type) {
      case 'attendance':
        $query = EventAttendanceHistory::query()->with(['event', 'registration.sessionAttendances', 'member', 'day', 'recorder', 'session']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        if (! empty($filters['attendance_status'])) {
          $query->where('status', $filters['attendance_status']);
        }
        $headers = ['event', 'registration_id', 'registration_number', 'name', 'event_day', 'session', 'check_in', 'check_out', 'status', 'seating_area', 'seat_counted', 'operator', 'source', 'occurred_at'];
        $rows = $query->get()->map(fn (EventAttendanceHistory $entry): array => [
          'event' => $entry->event?->title,
          'registration_id' => $entry->registration?->uuid,
          'registration_number' => $entry->registration?->registration_number,
          'name' => $entry->registration?->contactName(),
          'event_day' => $entry->day?->label,
          'session' => $entry->session?->title,
          'check_in' => $entry->status instanceof \BackedEnum && $entry->status->value === 'present'
            ? $entry->occurred_at?->toDateTimeString()
            : null,
          'check_out' => $entry->status instanceof \BackedEnum && $entry->status->value === 'checked_out'
            ? $entry->occurred_at?->toDateTimeString()
            : null,
          'status' => $entry->status instanceof \BackedEnum ? $entry->status->value : $entry->status,
          'seating_area' => $entry->registration?->sessionAttendances?->firstWhere('event_session_id', $entry->event_session_id)?->seating_area,
          'seat_counted' => $entry->registration?->sessionAttendances?->firstWhere('event_session_id', $entry->event_session_id)?->counts_toward_seating,
          'operator' => $entry->recorder?->name,
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
          ->with(['registration.event', 'registration.person.country', 'registration.person.member', 'option']);
        if ($eventId !== null) {
          $query->whereHas('registration', fn ($q) => $q->where('event_id', $eventId));
        }
        $headers = [
          'event', 'registration_id', 'registration_number', 'participant', 'membership', 'participant_category',
          'country', 'state', 'city', 'accommodation', 'occupancy_type', 'sharing_group', 'group_capacity',
          'actual_check_in', 'actual_check_out', 'actual_nights', 'group_billing_start', 'group_billing_end',
          'billable_nights', 'rate', 'currency', 'participant_amount', 'payment_status', 'allocation_status',
          'primary_payer', 'occupants', 'occupant_genders', 'occupant_countries', 'occupant_states',
        ];
        $rows = $query->get()->map(function (EventRegService $service) use ($filters): ?array {
          $details = is_array($service->details) ? $service->details : [];
          $registration = $service->registration;
          $profile = is_array($registration?->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];
          $payment = $registration?->payments()->where('purpose', 'accommodation')->latest('id')->first();
          $allocation = $registration?->accommodationAllocation;
          $membership = MembershipClassification::presentation(
            MembershipClassification::forPerson($registration?->person)
          );
          $occupancy = $details['occupancy_type'] ?? $service->option?->occupancyValue();
          $row = [
            'event' => $registration?->event?->title,
            'registration_id' => $registration?->uuid,
            'registration_number' => $registration?->registration_number,
            'participant' => $registration?->contactName(),
            'membership' => $membership,
            'participant_category' => $profile['participant_category'] ?? $profile['membership_status'] ?? null,
            'country' => $registration?->person?->country?->name,
            'state' => $registration?->person?->region,
            'city' => $registration?->person?->city,
            'accommodation' => $service->option?->name ?? ($details['option_name'] ?? null),
            'occupancy_type' => $occupancy,
            'sharing_group' => $details['progress'] ?? $details['group'] ?? null,
            'group_capacity' => $details['capacity'] ?? $service->option?->capacity,
            'actual_check_in' => $details['check_in'] ?? $allocation?->check_in_date?->toDateString(),
            'actual_check_out' => $details['check_out'] ?? $allocation?->check_out_date?->toDateString(),
            'actual_nights' => $details['actual_nights'] ?? null,
            'group_billing_start' => $details['billable_check_in'] ?? null,
            'group_billing_end' => $details['billable_check_out'] ?? null,
            'billable_nights' => $details['billable_nights'] ?? $details['nights'] ?? null,
            'rate' => $details['person_night'] ?? null,
            'currency' => $details['currency'] ?? $payment?->currency ?? $service->option?->currency,
            'participant_amount' => $details['person_total'] ?? ($payment?->amount),
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
            'allocation_status' => $allocation?->status,
            'primary_payer' => $details['primary_payer'] ?? true,
            'occupants' => collect($details['occupants'] ?? [])->pluck('name')->implode('; '),
            'occupant_genders' => collect($details['occupants'] ?? [])->pluck('gender')->implode('; '),
            'occupant_countries' => collect($details['occupants'] ?? [])->map(fn ($row) => $row['country'] ?? null)->filter()->implode('; '),
            'occupant_states' => collect($details['occupants'] ?? [])->map(fn ($row) => $row['state_region'] ?? $row['state'] ?? null)->filter()->implode('; '),
          ];

          return $this->rowMatchesFilters($row, $filters) ? $row : null;
        })->filter()->values()->all();

        return [$headers, $rows];

      case 'logistics':
        $query = \App\Modules\Events\Models\EventTransportTrip::query()
          ->with(['registration.event', 'registration.person.country', 'registration.person.member', 'option']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = [
          'event', 'registration_id', 'registration_number', 'participant', 'membership',
          'route', 'pickup_location', 'dropoff_location', 'pickup_date', 'pickup_time',
          'arrival_date', 'arrival_time', 'passengers', 'luggage', 'vehicle', 'vehicle_type',
          'amount', 'currency', 'payment_status', 'operational_status',
        ];
        $rows = $query->get()->map(function ($trip) use ($filters): ?array {
          $payment = $trip->payment_id
            ? EventRegistrationPayment::query()->find($trip->payment_id)
            : $trip->registration?->payments()->where('purpose', 'transport')->latest('id')->first();
          $flight = is_array($trip->flight_info) ? $trip->flight_info : [];
          $row = [
            'event' => $trip->registration?->event?->title,
            'registration_id' => $trip->registration?->uuid,
            'registration_number' => $trip->registration?->registration_number,
            'participant' => $trip->registration?->contactName(),
            'membership' => MembershipClassification::presentation(
              MembershipClassification::forPerson($trip->registration?->person)
            ),
            'route' => $trip->route,
            'pickup_location' => $trip->pickup_location,
            'dropoff_location' => $trip->dropoff_location,
            'pickup_date' => $trip->trip_date?->toDateString(),
            'pickup_time' => $trip->trip_time,
            'arrival_date' => $flight['arrival_date'] ?? $flight['expected_arrival_date'] ?? null,
            'arrival_time' => $flight['arrival_time'] ?? $flight['expected_arrival_time'] ?? null,
            'passengers' => $trip->passengers,
            'luggage' => $trip->luggage,
            'vehicle' => $trip->assigned_vehicle ?? $trip->option?->vehicle_name,
            'vehicle_type' => $trip->option?->vehicle_type,
            'amount' => (string) $trip->amount,
            'currency' => $trip->currency,
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
            'operational_status' => $trip->status,
          ];

          return $this->rowMatchesFilters($row, $filters) ? $row : null;
        })->filter()->values()->all();

        return [$headers, $rows];

      case 'travel':
        $query = \App\Modules\Events\Models\EventTravelRequest::query()
          ->with(['registration.event', 'registration.person.country', 'registration.person.member']);
        if ($eventId !== null) {
          $query->where('event_id', $eventId);
        }
        $headers = [
          'event', 'registration_id', 'registration_number', 'participant', 'membership',
          'origin', 'destination', 'departure_date', 'departure_time', 'return_date', 'return_time',
          'trip_type', 'airline_preference', 'travel_class', 'passenger_count', 'request_status',
          'quote', 'currency', 'payment_status', 'booking_status',
        ];
        $rows = $query->get()->map(function ($travel) use ($filters): ?array {
          $payment = $travel->payment_id
            ? EventRegistrationPayment::query()->find($travel->payment_id)
            : $travel->registration?->payments()->where('purpose', 'travel')->latest('id')->first();
          $row = [
            'event' => $travel->registration?->event?->title,
            'registration_id' => $travel->registration?->uuid,
            'registration_number' => $travel->registration?->registration_number,
            'participant' => $travel->registration?->contactName(),
            'membership' => MembershipClassification::presentation(
              MembershipClassification::forPerson($travel->registration?->person)
            ),
            'origin' => $travel->origin,
            'destination' => $travel->destination,
            'departure_date' => $travel->departure_date?->toDateString(),
            'departure_time' => $travel->preferred_departure_time,
            'return_date' => $travel->return_date?->toDateString(),
            'return_time' => $travel->preferred_return_time,
            'trip_type' => $travel->trip_type,
            'airline_preference' => $travel->airline_preference,
            'travel_class' => $travel->travel_class,
            'passenger_count' => $travel->passengers,
            'request_status' => $travel->status,
            'quote' => $travel->quote_amount !== null ? (string) $travel->quote_amount : null,
            'currency' => $travel->currency,
            'payment_status' => $payment?->status instanceof \BackedEnum ? $payment->status->value : $payment?->status,
            'booking_status' => $travel->status,
          ];

          return $this->rowMatchesFilters($row, $filters) ? $row : null;
        })->filter()->values()->all();

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

  /**
   * @param  array<string, mixed>  $row
   * @param  array<string, mixed>  $filters
   */
  private function rowMatchesFilters(array $row, array $filters): bool
  {
    if (! empty($filters['payment_status']) && strcasecmp((string) ($row['payment_status'] ?? ''), (string) $filters['payment_status']) !== 0) {
      return false;
    }
    if (! empty($filters['membership']) && strcasecmp((string) ($row['membership'] ?? ''), (string) $filters['membership']) !== 0) {
      return false;
    }
    $occupancy = $filters['occupancy_type'] ?? $filters['accommodation_type'] ?? null;
    if ($occupancy && strcasecmp((string) ($row['occupancy_type'] ?? ''), (string) $occupancy) !== 0) {
      return false;
    }
    if (! empty($filters['country'])) {
      $needle = strtolower((string) $filters['country']);
      if (! str_contains(strtolower((string) ($row['country'] ?? '')), $needle)) {
        return false;
      }
    }
    if (! empty($filters['state']) && ! str_contains(strtolower((string) ($row['state'] ?? '')), strtolower((string) $filters['state']))) {
      return false;
    }
    if (! empty($filters['route']) && ! str_contains(strtolower((string) ($row['route'] ?? '')), strtolower((string) $filters['route']))) {
      return false;
    }
    if (! empty($filters['vehicle']) && ! str_contains(strtolower((string) (($row['vehicle'] ?? '').' '.($row['vehicle_type'] ?? ''))), strtolower((string) $filters['vehicle']))) {
      return false;
    }
    if (! empty($filters['travel_status']) && strcasecmp((string) ($row['request_status'] ?? $row['status'] ?? ''), (string) $filters['travel_status']) !== 0) {
      return false;
    }

    return true;
  }

  private function filename(EventExportJob $job): string
  {
    $slug = Str::slug($job->export_type ?: 'registrations');

    return sprintf('%s-%s.%s', $slug, now()->format('Ymd-His'), $job->format);
  }
}
