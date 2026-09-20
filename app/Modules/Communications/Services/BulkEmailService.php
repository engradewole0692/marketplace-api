<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\User;
use App\Modules\Communications\Jobs\SendBulkEmailBatchJob;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Support\MembershipClassification;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BulkEmailService
{
  public function paginate(int $perPage = 20): LengthAwarePaginator
  {
    return BulkEmailJob::query()->with('creator:id,uuid,name,email')->latest()->paginate($perPage);
  }

  /**
   * Build recipient query from filters (does NOT execute — returns builder for counting or iteration).
   *
   * @param  array<string, mixed>  $filters
   */
  public function buildRecipientQuery(array $filters): Builder
  {
    $query = User::query()
      ->whereNotNull('email')
      ->where('email', '!=', '')
      ->where('status', '!=', 'inactive');

    if (! empty($filters['audience'])) {
      match ($filters['audience']) {
        'visitors' => $query->where('type', 'visitor'),
        'members' => $query->whereHas('member', fn ($q) => $q->where('status', 'active')),
        'staff' => $query->whereHas('roles', fn ($q) => $q->where('slug', 'staff')),
        'admins' => $query->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'super_admin'])),
        default => null,
      };
    }

    if (! empty($filters['country_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']));
    }

    if (! empty($filters['role_slug'])) {
      $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role_slug']));
    }

    if (! empty($filters['ministry_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('ministry_id', $filters['ministry_id']));
    }

    if (! empty($filters['course_id'])) {
      $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $filters['course_id']));
    }

    return $query->select('id', 'uuid', 'name', 'email');
  }

  /**
   * Unique recipient records for estimate and dispatch.
   *
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectRecipients(array $filters): Collection
  {
    return app(RecipientAudienceService::class)->collect($filters);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectUserRecipients(array $filters): Collection
  {
    return $this->buildRecipientQuery($filters)->get()->map(fn (User $user): array => [
      'email' => $user->email,
      'phone' => $user->phone ?? null,
      'name' => $user->name,
      'user_id' => $user->id,
      'person_id' => null,
    ])->values();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
   */
  public function collectEventRecipients(array $filters): Collection
  {
    return $this->eventRegistrationRecipients($filters);
  }

  /**
   * Count estimated recipients without creating the job.
   */
  public function estimateCount(array $filters): int
  {
    return (int) (app(RecipientAudienceService::class)->preview($filters)['valid_count'] ?? 0);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array<string, mixed>
   */
  public function preview(array $filters): array
  {
    return app(RecipientAudienceService::class)->preview($filters);
  }

  /**
   * Create a BulkEmailJob (status=queued), enqueue a batch job.
   */
  public function create(array $data, User $actor): BulkEmailJob
  {
    $filters = $data['recipient_filters'] ?? [];
    if (! empty($data['channel'])) {
      $filters['channel'] = $data['channel'];
    }
    $preview = $this->preview($filters);
    $scheduledAt = $this->parseSchedule($data['scheduled_at'] ?? null);

    $job = BulkEmailJob::query()->create([
      'uuid' => Str::uuid()->toString(),
      'subject' => $data['subject'],
      'html_body' => $data['html_body'],
      'text_body' => $data['text_body'] ?? null,
      'from_name' => $data['from_name'] ?? null,
      'from_email' => $data['from_email'] ?? null,
      'recipient_filters' => $filters,
      'estimated_count' => $preview['valid_count'],
      'status' => 'queued',
      'created_by' => $actor->id,
      'queued_at' => now(),
      'scheduled_at' => $scheduledAt,
    ]);

    $pending = new SendBulkEmailBatchJob($job->id);
    if ($scheduledAt !== null && $scheduledAt->isFuture()) {
      dispatch($pending->delay($scheduledAt));
    } else {
      dispatch($pending);
    }

    return $job;
  }

  private function parseSchedule(mixed $value): ?CarbonInterface
  {
    if ($value === null || $value === '') {
      return null;
    }

    try {
      $parsed = Carbon::parse((string) $value);
    } catch (\Throwable) {
      return null;
    }

    return $parsed->isFuture() ? $parsed : null;
  }

  public function cancel(BulkEmailJob $job): void
  {
    if (! in_array($job->status, ['queued', 'draft'], true)) {
      abort(422, 'Only queued or draft jobs can be cancelled.');
    }

    $job->status = 'cancelled';
    $job->save();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int}>
   */
  private function eventRegistrationRecipients(array $filters): Collection
  {
    $eventId = $this->resolveEventId($filters['event_id'] ?? null);
    $query = EventRegistration::query()
      ->with(['person.country', 'person.user', 'member.user', 'plannedSessions', 'sessionAttendances', 'services', 'payments'])
      ->whereNotIn('status', ['cancelled', 'declined']);

    if ($eventId !== null) {
      $query->where('event_id', $eventId);
    }

    $sessionId = $this->resolveSessionId($filters['event_session_id'] ?? null);
    if ($sessionId !== null) {
      $query->where(function ($builder) use ($sessionId): void {
        $builder->whereHas('plannedSessions', fn ($q) => $q->where('event_sessions.id', $sessionId))
          ->orWhereHas('sessionAttendances', fn ($q) => $q->where('event_session_id', $sessionId));
      });
    }

    $rows = $query->get()->filter(fn (EventRegistration $registration): bool => $this->matchesEventFilters($registration, $filters));

    return $rows->map(function (EventRegistration $registration): array {
      return [
        'email' => $registration->contactEmail(),
        'phone' => $registration->contactPhone(),
        'name' => $registration->contactName(),
        'user_id' => $registration->person?->user_id ?: $registration->member?->user_id,
        'person_id' => $registration->person_id,
      ];
    })->values();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function matchesEventFilters(EventRegistration $registration, array $filters): bool
  {
    $profile = is_array($registration->metadata['profile'] ?? null) ? $registration->metadata['profile'] : [];

    $gender = strtolower(trim((string) ($filters['gender'] ?? '')));
    if ($gender !== '' && strtolower((string) ($profile['gender'] ?? '')) !== $gender) {
      return false;
    }

    $category = strtolower(trim((string) ($filters['category'] ?? '')));
    if ($category !== '') {
      $value = strtolower((string) ($profile['participant_category'] ?? $profile['membership_status'] ?? $profile['category'] ?? ''));
      if ($value !== $category) {
        return false;
      }
    }

    if (array_key_exists('accommodation', $filters) && $filters['accommodation'] !== null && $filters['accommodation'] !== '') {
      $raw = $filters['accommodation'];
      $wants = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($wants === null) {
        $wants = in_array(strtolower((string) $raw), ['yes', 'true', '1', 'requested'], true);
      }
      $has = $registration->services->contains(function ($service) {
        $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

        return $type === EventRegServiceType::Accommodation;
      });
      if ($wants !== $has) {
        return false;
      }
    }

    if (array_key_exists('transport', $filters) && $filters['transport'] !== null && $filters['transport'] !== '') {
      $raw = $filters['transport'];
      $wants = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($wants === null) {
        $wants = in_array(strtolower((string) $raw), ['yes', 'true', '1', 'requested'], true);
      }
      $has = $registration->services->contains(function ($service) {
        $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

        return $type === EventRegServiceType::Transport;
      });
      if ($wants !== $has) {
        return false;
      }
    }

    $payment = strtolower(trim((string) ($filters['payment'] ?? '')));
    if ($payment !== '') {
      $statuses = $registration->payments->map(fn ($item) => strtolower($item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status));
      $settled = $statuses->contains(fn ($status) => in_array($status, ['paid', 'approved', 'waived'], true));
      if ($payment === 'paid' && ! $settled) {
        return false;
      }
      if ($payment === 'pending' && $settled) {
        return false;
      }
    }

    $attendance = strtolower(trim((string) ($filters['attendance'] ?? '')));
    if ($attendance !== '') {
      $statuses = $registration->sessionAttendances->map(
        fn ($row) => strtolower($row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status),
      );
      $present = $statuses->contains('checked_in');
      $out = $statuses->contains('checked_out');
      if (in_array($attendance, ['attended', 'checked_in'], true) && ! $present) {
        return false;
      }
      if ($attendance === 'absent' && $present) {
        return false;
      }
      if ($attendance === 'checked_out' && ! $out) {
        return false;
      }
    }

    $audience = strtolower(trim((string) ($filters['audience'] ?? '')));
    if (in_array($audience, ['members', 'visitors'], true)) {
      $class = MembershipClassification::forPerson($registration->person) ?: MembershipClassification::forMember($registration->member);
      $isMember = ($class['type'] ?? '') === 'approved_member';
      if ($audience === 'members' && ! $isMember) {
        return false;
      }
      if ($audience === 'visitors' && $isMember) {
        return false;
      }
    }

    $country = strtolower(trim((string) ($filters['country'] ?? '')));
    if ($country !== '') {
      $haystack = strtolower(trim(implode(' ', array_filter([
        $registration->person?->country?->name,
        $registration->person?->country?->code,
        $registration->person?->country?->slug,
        is_string($profile['country'] ?? null) ? $profile['country'] : null,
      ]))));
      if (! str_contains($haystack, $country)) {
        return false;
      }
    }

    $state = strtolower(trim((string) ($filters['state_region'] ?? $filters['state'] ?? '')));
    if ($state !== '') {
      $hay = strtolower(trim((string) ($registration->person?->region ?? $profile['state_region'] ?? $profile['region'] ?? '')));
      if (! str_contains($hay, $state)) {
        return false;
      }
    }

    $city = strtolower(trim((string) ($filters['city'] ?? '')));
    if ($city !== '' && ! str_contains(strtolower((string) ($registration->person?->city ?? $profile['city'] ?? '')), $city)) {
      return false;
    }

    $status = strtolower(trim((string) ($filters['registration_status'] ?? '')));
    if ($status !== '') {
      $current = strtolower($registration->status instanceof \BackedEnum ? $registration->status->value : (string) $registration->status);
      if ($current !== $status) {
        return false;
      }
    }

    $occupancy = strtolower(trim((string) ($filters['occupancy_type'] ?? '')));
    if ($occupancy !== '') {
      $details = is_array($registration->metadata['accommodation'] ?? null) ? $registration->metadata['accommodation'] : [];
      $value = strtolower((string) ($details['occupancy_type'] ?? $details['occupancy'] ?? ''));
      if ($value !== $occupancy) {
        $has = $registration->services->first(function ($service) {
          $type = $service->type instanceof EventRegServiceType ? $service->type : EventRegServiceType::tryFrom((string) $service->type);

          return $type === EventRegServiceType::Accommodation;
        });
        $meta = is_array($has?->details) ? $has->details : [];
        $value = strtolower((string) ($meta['occupancy_type'] ?? $meta['occupancy'] ?? ''));
      }
      if ($value !== $occupancy) {
        return false;
      }
    }

    $seat = strtolower(trim((string) ($filters['seat_counting'] ?? '')));
    if (in_array($seat, ['yes', 'no'], true)) {
      $counted = $registration->sessionAttendances->contains(fn ($row) => (bool) $row->counts_toward_seating);
      if ($seat === 'yes' && ! $counted) {
        return false;
      }
      if ($seat === 'no' && $counted) {
        return false;
      }
    }

    return true;
  }

  private function resolveEventId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }

    return Event::query()->where('uuid', (string) $value)->value('id');
  }

  private function resolveSessionId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value;
    }

    return EventSession::query()->where('uuid', (string) $value)->value('id');
  }
}
