<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Modules\Events\Http\Resources\EventSessionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SessionResolutionService implements ServiceContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   status: 'resolved'|'ambiguous'|'none',
     *   session: ?EventSession,
     *   candidates: Collection<int, EventSession>,
     *   has_configured_sessions: bool
     * }
     */
    public function resolve(Event $event, array $data = [], ?Carbon $at = null): array
    {
        $at ??= now($event->timezone ?: config('app.timezone'));
        $sessions = $this->activeTimedSessions($event);
        $explicit = $this->findExplicit($event, $data['event_session_id'] ?? null);

        if ($explicit !== null) {
            return [
                'status' => 'resolved',
                'session' => $explicit,
                'candidates' => $sessions,
                'has_configured_sessions' => $sessions->isNotEmpty(),
            ];
        }

        if ($sessions->isEmpty()) {
            return [
                'status' => 'none',
                'session' => null,
                'candidates' => $sessions,
                'has_configured_sessions' => false,
            ];
        }

        $matches = $sessions->filter(fn (EventSession $session): bool => $this->covers($event, $session, $at))->values();

        if ($matches->count() === 1) {
            return [
                'status' => 'resolved',
                'session' => $matches->first(),
                'candidates' => $sessions,
                'has_configured_sessions' => true,
            ];
        }

        return [
            'status' => $matches->count() > 1 ? 'ambiguous' : 'none',
            'session' => null,
            'candidates' => $matches->isNotEmpty() ? $matches : $sessions,
            'has_configured_sessions' => true,
        ];
    }

    /**
     * @return Collection<int, EventSession>
     */
    public function activeTimedSessions(Event $event): Collection
    {
        return $event->sessions()
            ->where(function ($query): void {
                $query->where('is_active', true)->orWhereNull('is_active');
            })
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->orderBy('sort_order')
            ->orderBy('starts_at')
            ->get();
    }

    public function covers(Event $event, EventSession $session, Carbon $at): bool
    {
        if ($session->starts_at === null || $session->ends_at === null) {
            return false;
        }

        $before = (int) ($session->grace_before_minutes ?? $event->default_grace_before_minutes ?? 15);
        $after = (int) ($session->grace_after_minutes ?? $event->default_grace_after_minutes ?? 15);
        $start = $session->starts_at->copy()->subMinutes(max(0, $before));
        $end = $session->ends_at->copy()->addMinutes(max(0, $after));

        return $at->betweenIncluded($start, $end);
    }

    public function findExplicit(Event $event, mixed $sessionId): ?EventSession
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $query = EventSession::query()->where('event_id', $event->id);
        if (is_numeric($sessionId)) {
            return $query->where('id', (int) $sessionId)->first();
        }

        return $query->where('uuid', (string) $sessionId)->first();
    }

    /**
     * @param  Collection<int, EventSession>  $sessions
     * @return list<array<string, mixed>>
     */
    public function serializeSessions(Collection $sessions): array
    {
        return $sessions->map(fn (EventSession $session): array => (new EventSessionResource($session))->resolve())->values()->all();
    }
}
