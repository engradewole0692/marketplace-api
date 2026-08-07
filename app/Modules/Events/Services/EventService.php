<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EventService implements ServiceContract
{
  public function __construct(
    private readonly EventAuditService $auditService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Event::query()
      ->with(['ministry', 'country', 'region', 'venue'])
      ->withCount('registrations')
      ->orderByDesc('starts_at');

    foreach (['ministry_id', 'event_category_id', 'venue_id', 'country_id', 'region_id', 'status', 'visibility'] as $field) {
      if (! empty($filters[$field])) {
        $query->where($field, $filters[$field]);
      }
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('theme', 'like', "%{$search}%"));
    }

    if (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== null && $filters['is_featured'] !== '') {
      $query->where('is_featured', filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN));
    }

    if (array_key_exists('is_paid', $filters) && $filters['is_paid'] !== null && $filters['is_paid'] !== '') {
      $query->where('is_paid', filter_var($filters['is_paid'], FILTER_VALIDATE_BOOLEAN));
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Event
  {
    $data['slug'] ??= Str::slug($data['title']);
    $data['created_by_user_id'] = $actor->id;
    $data['updated_by_user_id'] = $actor->id;

    if (($data['status'] ?? null) === EventStatus::Published->value || ($data['status'] ?? null) === EventStatus::Published) {
      $data['published_at'] ??= now();
      $data['published_by_user_id'] ??= $actor->id;
      $data['status'] = EventStatus::Published->value;
    }

    $sessions = $data['sessions'] ?? null;
    unset($data['sessions']);

    $event = Event::query()->create($data);
    $this->auditService->record(EventAuditEventType::Created, $event, $actor, Event::class, $event->id, null, ['title' => $event->title]);

    if (is_array($sessions)) {
      $this->syncSessions($event, $sessions);
    }

    return $event->fresh(['ministry', 'country', 'region', 'venue']);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Event $event, array $data, User $actor): Event
  {
    $old = $event->only(['title', 'status', 'visibility', 'starts_at', 'ends_at']);
    if (isset($data['title']) && empty($data['slug'])) {
      $data['slug'] = Str::slug($data['title']);
    }

    $statusValue = $data['status'] ?? null;
    if ($statusValue instanceof \BackedEnum) {
      $statusValue = $statusValue->value;
    }
    if ($statusValue === EventStatus::Published->value) {
      $data['published_at'] = $event->published_at ?? now();
      $data['published_by_user_id'] = $event->published_by_user_id ?? $actor->id;
    }

    $sessions = $data['sessions'] ?? null;
    unset($data['sessions']);

    $data['updated_by_user_id'] = $actor->id;
    $event->fill($data);
    $event->save();

    $this->auditService->record(EventAuditEventType::Updated, $event, $actor, Event::class, $event->id, $old, $event->only(array_keys($old)));

    if (is_array($sessions)) {
      $this->syncSessions($event, $sessions);
    }

    return $event->fresh(['ministry', 'country', 'region', 'venue']);
  }

  /**
   * @param  list<array<string, mixed>>  $sessions
   */
  private function syncSessions(Event $event, array $sessions): void
  {
    foreach ($sessions as $sessionData) {
      if (! is_array($sessionData)) {
        continue;
      }

      $sessionData['event_id'] = $event->id;
      $uuid = $sessionData['id'] ?? null;
      unset($sessionData['id']);

      if ($uuid !== null) {
        $session = EventSession::query()->where('uuid', $uuid)->first();
        if ($session !== null) {
          $session->fill($sessionData);
          $session->save();
          continue;
        }
      }

      EventSession::query()->create($sessionData);
    }
  }

  public function publish(Event $event, User $actor): Event
  {
    $old = ['status' => $event->status instanceof \BackedEnum ? $event->status->value : $event->status];
    $event->status = EventStatus::Published;
    $event->published_at ??= now();
    $event->published_by_user_id = $actor->id;
    $event->updated_by_user_id = $actor->id;
    $event->save();

    $this->auditService->record(EventAuditEventType::Published, $event, $actor, Event::class, $event->id, $old, ['status' => EventStatus::Published->value]);

    return $event->fresh();
  }

  public function unpublish(Event $event, User $actor): Event
  {
    $old = ['status' => $event->status instanceof \BackedEnum ? $event->status->value : $event->status];
    $event->status = EventStatus::Draft;
    $event->updated_by_user_id = $actor->id;
    $event->save();

    $this->auditService->record(EventAuditEventType::Updated, $event, $actor, Event::class, $event->id, $old, ['status' => EventStatus::Draft->value]);

    return $event->fresh();
  }

  public function archive(Event $event, User $actor): Event
  {
    $old = ['status' => $event->status instanceof \BackedEnum ? $event->status->value : $event->status];
    $event->status = EventStatus::Archived;
    $event->updated_by_user_id = $actor->id;
    $event->save();

    $this->auditService->record(EventAuditEventType::Updated, $event, $actor, Event::class, $event->id, $old, ['status' => EventStatus::Archived->value]);

    return $event->fresh();
  }

  public function duplicate(Event $event, User $actor): Event
  {
    $event->loadMissing(['sessions', 'speakers']);

    $payload = [
      'title' => $event->title.' (Copy)',
      'slug' => null,
      'ministry_id' => $event->ministry_id,
      'event_category_id' => $event->event_category_id,
      'venue_id' => $event->venue_id,
      'country_id' => $event->country_id,
      'region_id' => $event->region_id,
      'theme' => $event->theme,
      'theme_scripture' => $event->theme_scripture,
      'summary' => $event->summary,
      'description' => $event->description,
      'starts_at' => $event->starts_at,
      'ends_at' => $event->ends_at,
      'timezone' => $event->timezone,
      'registration_opens_at' => $event->registration_opens_at,
      'registration_deadline' => $event->registration_deadline,
      'capacity' => $event->capacity,
      'visibility' => $event->visibility instanceof \BackedEnum ? $event->visibility->value : $event->visibility,
      'status' => EventStatus::Draft->value,
      'check_in_enabled' => $event->check_in_enabled,
      'certificate_enabled' => $event->certificate_enabled,
      'attendance_required' => $event->attendance_required,
      'is_featured' => false,
      'is_paid' => $event->is_paid,
      'price' => $event->price,
      'currency' => $event->currency,
      'seo_title' => $event->seo_title,
      'seo_description' => $event->seo_description,
      'announcement' => $event->announcement,
      'cover_media_id' => $event->cover_media_id,
      'banner_media_id' => $event->banner_media_id,
    ];

    $clone = $this->create($payload, $actor);

    foreach ($event->sessions as $session) {
      EventSession::query()->create([
        'event_id' => $clone->id,
        'title' => $session->title,
        'description' => $session->description,
        'starts_at' => $session->starts_at,
        'ends_at' => $session->ends_at,
        'track' => $session->track,
        'room' => $session->room,
        'moderator_user_id' => $session->moderator_user_id,
        'capacity' => $session->capacity,
        'sort_order' => $session->sort_order,
        'resources_json' => $session->resources_json,
        'session_type' => $session->session_type,
        'location' => $session->location,
        'speaker_id' => $session->speaker_id,
        'metadata' => $session->metadata,
      ]);
    }

    return $clone->fresh(['ministry', 'category', 'venue']);
  }

  public function delete(Event $event, User $actor): void
  {
    $event->deleted_by_user_id = $actor->id;
    $event->save();
    $event->delete();
    $this->auditService->record(EventAuditEventType::Deleted, $event, $actor, Event::class, $event->id, ['title' => $event->title], null);
  }
}
