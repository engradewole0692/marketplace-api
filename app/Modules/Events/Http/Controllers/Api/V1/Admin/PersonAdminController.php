<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Person;
use App\Modules\Events\Http\Resources\EventRegistrationResource;
use App\Modules\Events\Http\Resources\PersonResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\PersonIdentityService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonAdminController extends ApiController
{
    public function index(Request $request, PersonIdentityService $service): JsonResponse
    {
        $this->authorize('viewAny', EventRegistration::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->responder->success(
            data: PaginatedResponseBuilder::fromPaginator(
                $service->paginate($request->query()),
                PersonResource::class,
            ),
            message: 'Participants retrieved.',
        );
    }

    public function show(Person $person, PersonIdentityService $service): JsonResponse
    {
        $this->authorize('viewAny', EventRegistration::class);

        $person->load(['member', 'country']);
        $history = $service->history($person);

        return $this->responder->success(
            data: [
                'person' => new PersonResource($person),
                'registrations' => EventRegistrationResource::collection(
                    $history->each->setRelation('person', $person),
                ),
            ],
            message: 'Participant retrieved.',
        );
    }
}
