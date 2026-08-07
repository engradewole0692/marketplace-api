<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class RegistrantExportBuilder
{
  /**
   * @return list<string>
   */
  public static function headers(): array
  {
    return ['registration_number', 'name', 'email', 'phone', 'status', 'event', 'submitted_at'];
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public static function filteredQuery(?int $eventId, array $filters): Builder
  {
    $query = EventRegistration::query()->with(['event', 'member']);

    if ($eventId !== null) {
      $query->where('event_id', $eventId);
    }

    if (! empty($filters['registration_status'])) {
      $query->where('status', $filters['registration_status']);
    }

    if (! empty($filters['ministry_id'])) {
      $query->whereHas('event', fn ($q) => $q->where('ministry_id', $filters['ministry_id']));
    }

    if (! empty($filters['country_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']));
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
    return self::filteredQuery($eventId, $filters)
      ->get()
      ->map(fn (EventRegistration $registration): array => [
        'registration_number' => $registration->registration_number,
        'name' => $registration->contactName(),
        'email' => $registration->contactEmail(),
        'phone' => $registration->contactPhone(),
        'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status,
        'event' => $registration->event?->title,
        'submitted_at' => $registration->submitted_at?->toDateTimeString(),
      ])
      ->all();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public static function buildContext(?int $eventId, array $filters, ?User $requester, int $recordCount): array
  {
    $event = $eventId !== null ? \App\Modules\Events\Models\Event::query()->find($eventId) : null;

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
}
