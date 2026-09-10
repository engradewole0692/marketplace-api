<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Http\Resources\EventRegServiceResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventRegServiceAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventRegServiceAdminController extends ApiController
{
    public function update(Request $request, EventRegistration $registration, EventRegServiceAdminService $service): JsonResponse
    {
        $this->authorize('update', $registration);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(EventRegServiceType::class)],
            'status' => ['nullable', 'string', 'max:40'],
            'details' => ['nullable', 'array'],
        ]);

        $row = $service->upsert(
            $registration,
            EventRegServiceType::from($validated['type']),
            $validated,
            $request->user(),
        );

        return $this->responder->success(
            data: ['service' => new EventRegServiceResource($row)],
            message: 'Service updated.',
        );
    }
}
