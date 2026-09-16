<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Enums\SeatClassificationScope;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\SeatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventSeatClassificationAdminController extends ApiController
{
    public function index(Event $event, SeatingService $service): JsonResponse
    {
        $this->authorize('view', $event);

        return $this->responder->success(
            data: ['classifications' => $service->listClassifications($event)->map(fn ($row) => $this->transform($row))->values()],
            message: 'Seat classifications retrieved.',
        );
    }

    public function sync(Event $event, Request $request, SeatingService $service): JsonResponse
    {
        $this->authorize('update', $event);
        $validated = $request->validate([
            'classifications' => ['required', 'array'],
            'classifications.*.scope' => ['required', Rule::enum(SeatClassificationScope::class)],
            'classifications.*.match_key' => ['required', 'string', 'max:80'],
            'classifications.*.label' => ['nullable', 'string', 'max:120'],
            'classifications.*.counts_toward_seating' => ['boolean'],
        ]);

        $rows = $service->syncClassifications($event, $validated['classifications']);

        return $this->responder->success(
            data: ['classifications' => $rows->map(fn ($row) => $this->transform($row))->values()],
            message: 'Seat classifications saved.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(\App\Modules\Events\Models\EventSeatClassification $row): array
    {
        return [
            'id' => $row->uuid,
            'scope' => $row->scope instanceof \BackedEnum ? $row->scope->value : $row->scope,
            'match_key' => $row->match_key,
            'label' => $row->label,
            'counts_toward_seating' => (bool) $row->counts_toward_seating,
        ];
    }
}
