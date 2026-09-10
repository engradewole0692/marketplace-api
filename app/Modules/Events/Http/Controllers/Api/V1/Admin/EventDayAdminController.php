<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventDayResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDay;
use App\Modules\Events\Services\EventDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventDayAdminController extends ApiController
{
    public function index(Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $days = app(EventDayService::class)->ensureDays($event);

        return $this->responder->success(
            data: ['days' => EventDayResource::collection($days)],
            message: 'Event days retrieved.',
        );
    }

    public function store(Request $request, Event $event, EventDayService $service): JsonResponse
    {
        $this->authorize('update', $event);
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'label' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $max = (int) EventDay::query()->where('event_id', $event->id)->max('day_index');
        $day = EventDay::query()->create([
            'event_id' => $event->id,
            'day_index' => $max + 1,
            'date' => $validated['date'],
            'label' => $validated['label'] ?? 'Day '.($max + 1),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'sort_order' => $max + 1,
        ]);
        $event->attendance_mode = 'daily';
        $event->save();

        return $this->responder->success(
            data: ['day' => new EventDayResource($day)],
            message: 'Event day created.',
            status: 201,
        );
    }

    public function sync(Event $event, EventDayService $service): JsonResponse
    {
        $this->authorize('update', $event);
        $days = $service->syncFromEvent($event);

        return $this->responder->success(
            data: ['days' => EventDayResource::collection($days)],
            message: 'Event days synchronized from dates.',
        );
    }
}
