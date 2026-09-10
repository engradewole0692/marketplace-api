<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Enums\AttendanceMode;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class EventDayService implements ServiceContract
{
    public function syncFromEvent(Event $event, bool $replaceEmptyOnly = false): Collection
    {
        $event->refresh();
        $existing = EventDay::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('day_index')->get();
        if ($replaceEmptyOnly && $existing->isNotEmpty()) {
            return $existing;
        }

        $dates = $this->datesForEvent($event);
        if ($dates === []) {
            return $existing;
        }

        $keptIds = [];
        foreach ($dates as $index => $date) {
            $dayIndex = $index + 1;
            $day = EventDay::query()->updateOrCreate(
                [
                    'event_id' => $event->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'day_index' => $dayIndex,
                    'label' => 'Day '.$dayIndex,
                    'starts_at' => $date->copy()->setTimeFrom($event->starts_at ?? $date),
                    'ends_at' => $event->ends_at
                        ? $date->copy()->setTimeFrom($event->ends_at)
                        : $date->copy()->endOfDay(),
                    'sort_order' => $dayIndex,
                ],
            );
            $keptIds[] = $day->id;
        }

        EventDay::query()
            ->where('event_id', $event->id)
            ->whereNotIn('id', $keptIds)
            ->whereDoesntHave('attendances')
            ->delete();

        return EventDay::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('day_index')->get();
    }

    public function ensureDays(Event $event): Collection
    {
        $days = EventDay::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('day_index')->get();
        if ($days->isNotEmpty()) {
            return $days;
        }

        return $this->syncFromEvent($event);
    }

    public function resolveCurrentDay(Event $event, ?string $dayUuid = null, ?Carbon $now = null): EventDay
    {
        $days = $this->ensureDays($event);
        if ($days->isEmpty()) {
            throw ValidationException::withMessages([
                'event_day_id' => ['This event has no attendance days configured.'],
            ]);
        }

        if ($dayUuid) {
            $explicit = $days->first(fn (EventDay $day) => $day->uuid === $dayUuid)
                ?? EventDay::query()->where('event_id', $event->id)->where('uuid', $dayUuid)->first();
            if ($explicit === null) {
                throw ValidationException::withMessages([
                    'event_day_id' => ['The selected event day does not belong to this event.'],
                ]);
            }

            return $explicit;
        }

        $now ??= now();
        $today = $days->first(fn (EventDay $day) => $day->date?->toDateString() === $now->toDateString());
        if ($today !== null) {
            return $today;
        }

        $mode = $event->attendance_mode instanceof AttendanceMode
            ? $event->attendance_mode
            : AttendanceMode::tryFrom((string) ($event->attendance_mode ?? 'single'));

        if ($mode === AttendanceMode::Single) {
            return $days->first();
        }

        $upcoming = $days->first(fn (EventDay $day) => $day->date !== null && $day->date->gte($now->copy()->startOfDay()));

        return $upcoming ?? $days->last();
    }

    /**
     * @return list<Carbon>
     */
    public function datesForEvent(Event $event): array
    {
        if ($event->starts_at === null) {
            return [now()->startOfDay()];
        }

        $tz = $event->timezone ?: config('app.timezone', 'UTC');
        $start = $event->starts_at->copy()->timezone($tz)->startOfDay();
        $end = $event->ends_at
            ? $event->ends_at->copy()->timezone($tz)->startOfDay()
            : $start->copy();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $mode = $event->attendance_mode instanceof AttendanceMode
            ? $event->attendance_mode
            : AttendanceMode::tryFrom((string) ($event->attendance_mode ?? '')) ?? AttendanceMode::Single;

        if ($mode === AttendanceMode::Single) {
            return [$start];
        }

        $dates = [];
        $cursor = $start->copy();
        $guard = 0;
        while ($cursor->lte($end) && $guard < 31) {
            $dates[] = $cursor->copy();
            $cursor->addDay();
            $guard++;
        }

        return $dates;
    }

    public function inferMode(Event $event): AttendanceMode
    {
        if ($event->starts_at === null || $event->ends_at === null) {
            return AttendanceMode::Single;
        }
        $tz = $event->timezone ?: config('app.timezone', 'UTC');
        $start = Carbon::parse($event->starts_at)->timezone($tz)->toDateString();
        $end = Carbon::parse($event->ends_at)->timezone($tz)->toDateString();

        return $start === $end ? AttendanceMode::Single : AttendanceMode::Daily;
    }
}
