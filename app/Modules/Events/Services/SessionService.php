<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSession;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class SessionService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $data
   */
  public function create(Event $event, array $data): EventSession
  {
    $this->detectConflicts(
      $event,
      $data['starts_at'] ?? null,
      $data['ends_at'] ?? null,
      $data['room'] ?? null,
      $data['speaker_id'] ?? null,
    );

    $data['event_id'] = $event->id;

    return EventSession::query()->create($data)->fresh();
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(EventSession $session, array $data): EventSession
  {
    $session->loadMissing('event');
    $event = $session->event;

    if ($event !== null) {
      $this->detectConflicts(
        $event,
        $data['starts_at'] ?? $session->starts_at,
        $data['ends_at'] ?? $session->ends_at,
        $data['room'] ?? $session->room,
        $data['speaker_id'] ?? $session->speaker_id,
        $session->id,
      );
    }

    $session->fill($data);
    $session->save();

    return $session->fresh();
  }

  public function delete(EventSession $session): void
  {
    $session->delete();
  }

  public function detectConflicts(
    Event $event,
    Carbon|string|null $startsAt,
    Carbon|string|null $endsAt,
    ?string $room = null,
    int|string|null $speakerId = null,
    ?int $excludeSessionId = null,
  ): void {
    if ($startsAt === null || $endsAt === null) {
      return;
    }

    $starts = $startsAt instanceof Carbon ? $startsAt : Carbon::parse($startsAt);
    $ends = $endsAt instanceof Carbon ? $endsAt : Carbon::parse($endsAt);

    if ($ends->lte($starts)) {
      throw ValidationException::withMessages([
        'ends_at' => ['Session end time must be after start time.'],
      ]);
    }

    $query = EventSession::query()
      ->where('event_id', $event->id)
      ->where(function ($inner) use ($starts, $ends): void {
        $inner->where(function ($q) use ($starts, $ends): void {
          $q->where('starts_at', '<', $ends)->where('ends_at', '>', $starts);
        });
      });

    if ($excludeSessionId !== null) {
      $query->where('id', '!=', $excludeSessionId);
    }

    if (! empty($room)) {
      (clone $query)->where('room', $room)->exists()
        && throw ValidationException::withMessages([
          'room' => ['Another session in this room overlaps the requested time.'],
        ]);
    }

    if (! empty($speakerId)) {
      (clone $query)->where('speaker_id', $speakerId)->exists()
        && throw ValidationException::withMessages([
          'speaker_id' => ['This speaker is already scheduled during the requested time.'],
        ]);
    }
  }
}
