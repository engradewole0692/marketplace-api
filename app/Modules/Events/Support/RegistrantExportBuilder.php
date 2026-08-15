<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\AttendanceStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendanceHistory;
use App\Modules\Events\Models\EventCheckIn;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationQuestion;
use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class RegistrantExportBuilder
{
  /**
   * @return list<string>
   */
  public static function headers(?int $eventId = null): array
  {
    [$headers] = self::dynamicColumns($eventId);

    return $headers;
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public static function filteredQuery(?int $eventId, array $filters): Builder
  {
    $query = EventRegistration::query()
      ->with(['event', 'member', 'answers.question', 'checkIns', 'attendanceHistories', 'payments'])
      ->orderByDesc('created_at');

    if ($eventId !== null) {
      $query->where('event_id', $eventId);
    }

    if (! empty($filters['registration_status'])) {
      $query->where('status', $filters['registration_status']);
    }

    if (! empty($filters['attendance_status'])) {
      $attendanceStatus = (string) $filters['attendance_status'];
      if ($attendanceStatus === 'checked_in') {
        $query->where('status', 'checked_in');
      } elseif ($attendanceStatus === 'checked_out' || $attendanceStatus === 'attended') {
        $query->where('status', 'attended');
      } elseif ($attendanceStatus === 'not_checked_in') {
        $query->whereNotIn('status', ['checked_in', 'attended']);
      } elseif ($attendanceStatus === 'present') {
        $query->whereHas('attendanceHistories', fn ($q) => $q->where('status', AttendanceStatus::Present->value));
      } else {
        $query->whereHas('attendanceHistories', fn ($q) => $q->where('status', $attendanceStatus));
      }
    }

    if (! empty($filters['payment_status'])) {
      $query->whereHas('payments', fn ($q) => $q->where('status', $filters['payment_status']));
    }

    if (! empty($filters['ministry_id'])) {
      $query->whereHas('event', fn ($q) => $q->where('ministry_id', $filters['ministry_id']));
    }

    if (! empty($filters['country_id'])) {
      $query->where(function ($builder) use ($filters): void {
        $builder->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']))
          ->orWhereHas('event', fn ($q) => $q->where('country_id', $filters['country_id']));
      });
    }

    if (! empty($filters['region_id'])) {
      $query->whereHas('event', fn ($q) => $q->where('region_id', $filters['region_id']));
    }

    if (! empty($filters['gender'])) {
      $gender = (string) $filters['gender'];
      $query->where(function ($builder) use ($gender): void {
        $builder->where('metadata->profile->gender', $gender)
          ->orWhereHas('member', fn ($q) => $q->where('gender', $gender));
      });
    }

    if (! empty($filters['occupation'])) {
      $occupation = '%'.$filters['occupation'].'%';
      $query->where('metadata->profile->occupation', 'like', $occupation);
    }

    if (array_key_exists('accommodation_required', $filters) && $filters['accommodation_required'] !== null && $filters['accommodation_required'] !== '') {
      $query->where('accommodation_required', filter_var($filters['accommodation_required'], FILTER_VALIDATE_BOOLEAN));
    }

    $from = $filters['date_from'] ?? $filters['from_date'] ?? null;
    $to = $filters['date_to'] ?? $filters['to_date'] ?? null;

    if (! empty($from)) {
      $query->whereDate('created_at', '>=', $from);
    }

    if (! empty($to)) {
      $query->whereDate('created_at', '<=', $to);
    }

    return $query;
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return list<array<string, string|null>>
   */
  public static function buildRows(?int $eventId, array $filters): array
  {
    [$headers, $dynamicKeys] = self::dynamicColumns($eventId);

    return self::filteredQuery($eventId, $filters)
      ->get()
      ->map(function (EventRegistration $registration) use ($headers, $dynamicKeys): array {
        $profile = is_array($registration->metadata['profile'] ?? null)
          ? $registration->metadata['profile']
          : [];

        $checkIn = $registration->relationLoaded('checkIns')
          ? $registration->checkIns->sortByDesc('checked_in_at')->first()
          : $registration->checkIns()->orderByDesc('checked_in_at')->first();

        $checkOut = $registration->relationLoaded('attendanceHistories')
          ? $registration->attendanceHistories
            ->first(fn ($h) => ($h->status instanceof \BackedEnum ? $h->status->value : $h->status) === AttendanceStatus::CheckedOut->value)
          : $registration->attendanceHistories()
            ->where('status', AttendanceStatus::CheckedOut->value)
            ->orderByDesc('occurred_at')
            ->first();

        $latestPayment = $registration->relationLoaded('payments')
          ? $registration->payments->sortByDesc('id')->first()
          : null;

        $row = [
          'registration_number' => $registration->registration_number,
          'name' => $registration->contactName(),
          'email' => $registration->contactEmail(),
          'phone' => $registration->contactPhone(),
          'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status,
          'payment_status' => $latestPayment?->status instanceof \BackedEnum
            ? $latestPayment->status->value
            : ($latestPayment?->status !== null ? (string) $latestPayment->status : null),
          'event' => $registration->event?->title,
          'submitted_at' => $registration->submitted_at?->toDateTimeString(),
          'check_in_at' => $checkIn?->checked_in_at?->toDateTimeString(),
          'check_out_at' => $checkOut?->occurred_at?->toDateTimeString(),
          'attendance_status' => match (true) {
            $checkOut !== null => 'checked_out',
            $checkIn !== null => 'checked_in',
            default => 'not_checked_in',
          },
          'attended' => ($checkIn !== null || in_array(
            $registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status,
            ['checked_in', 'attended'],
            true,
          )) ? 'yes' : 'no',
        ];

        foreach ($dynamicKeys as $key => $meta) {
          if (($meta['source'] ?? '') === 'question') {
            $answer = $registration->answers->first(
              fn ($item) => $item->question?->uuid === $meta['id'] || (string) $item->question_id === (string) ($meta['numeric_id'] ?? ''),
            );
            $row[$key] = $answer?->answer_text
              ?? (is_array($answer?->answer_json) ? json_encode($answer->answer_json) : null);
            continue;
          }

          if (($meta['storage'] ?? '') === 'column') {
            $value = $registration->{$meta['field_key']} ?? null;
            if (is_bool($value)) {
              $row[$key] = $value ? 'yes' : 'no';
            } elseif ($value instanceof \DateTimeInterface) {
              $row[$key] = $value->format('Y-m-d');
            } else {
              $row[$key] = $value !== null ? (string) $value : null;
            }
            continue;
          }

          if (($meta['storage'] ?? '') === 'profile') {
            $value = $profile[$meta['field_key']] ?? null;
            $row[$key] = is_bool($value) ? ($value ? 'yes' : 'no') : ($value !== null ? (string) $value : null);
            continue;
          }
        }

        $ordered = [];
        foreach ($headers as $header) {
          $ordered[$header] = $row[$header] ?? null;
        }

        return $ordered;
      })
      ->all();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public static function buildContext(?int $eventId, array $filters, ?User $requester, int $recordCount): array
  {
    $event = $eventId !== null ? Event::query()->find($eventId) : null;

    return [
      'organization_name' => (string) config('app.name'),
      'event_title' => $event?->title,
      'event_date' => EventPresentation::eventDate($event),
      'venue' => EventPresentation::venue($event),
      'generated_at' => Carbon::now()->toDateTimeString(),
      'generated_by' => $requester?->display_name ?? $requester?->name,
      'record_count' => $recordCount,
    ];
  }

  /**
   * @return array{0: list<string>, 1: array<string, array<string, mixed>>}
   */
  private static function dynamicColumns(?int $eventId): array
  {
    $headers = [
      'registration_number',
      'name',
      'email',
      'phone',
      'status',
      'payment_status',
      'event',
      'submitted_at',
      'check_in_at',
      'check_out_at',
      'attendance_status',
      'attended',
    ];
    $dynamicKeys = [];

    if ($eventId === null) {
      return [$headers, $dynamicKeys];
    }

    $event = Event::query()->find($eventId);
    if ($event === null) {
      return [$headers, $dynamicKeys];
    }

    $config = app(RegistrationFormConfigService::class);
    $settings = $config->listFieldSettings($event);

    foreach ($settings as $setting) {
      if (! $setting->is_enabled) {
        continue;
      }

      if (in_array($setting->field_key, ['name', 'email', 'phone'], true)) {
        continue;
      }

      $header = $setting->field_key;
      if (! in_array($header, $headers, true)) {
        $headers[] = $header;
      }

      $dynamicKeys[$header] = [
        'source' => 'standard',
        'field_key' => $setting->field_key,
        'storage' => in_array($setting->field_key, RegistrationFormConfigService::COLUMN_FIELDS, true)
          ? 'column'
          : 'profile',
      ];
    }

    $questions = EventRegistrationQuestion::query()
      ->where('event_id', $eventId)
      ->where('is_enabled', true)
      ->orderBy('sort_order')
      ->get();

    foreach ($questions as $question) {
      $header = $question->field_key ?: ('question_'.$question->uuid);
      if (! in_array($header, $headers, true)) {
        $headers[] = $header;
      }
      $dynamicKeys[$header] = [
        'source' => 'question',
        'id' => $question->uuid,
        'numeric_id' => $question->id,
      ];
    }

    return [$headers, $dynamicKeys];
  }
}
