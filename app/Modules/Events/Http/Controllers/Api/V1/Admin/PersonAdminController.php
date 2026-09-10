<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Person;
use App\Modules\Events\Http\Resources\EventRegistrationResource;
use App\Modules\Events\Http\Resources\PersonResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\PersonIdentityService;
use App\Modules\Events\Services\PersonMergeService;
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

    public function previewMerge(Request $request, PersonMergeService $service): JsonResponse
    {
        $this->authorize('permission', 'events.manage');
        $validated = $this->mergePayload($request);

        return $this->responder->success(
            data: $service->preview($validated['source'], $validated['target'], $validated['prefer_source_fields']),
            message: 'Person merge preview generated.',
        );
    }

    public function merge(Request $request, PersonMergeService $service): JsonResponse
    {
        $this->authorize('permission', 'events.manage');
        $validated = $this->mergePayload($request, true);

        $person = $service->execute(
            $validated['source'],
            $validated['target'],
            $request->user(),
            $validated['prefer_source_fields'],
        );

        return $this->responder->success(
            data: [
                'person' => new PersonResource($person->load(['member', 'country'])),
            ],
            message: 'People merged.',
        );
    }

    /**
     * @return array{source: Person, target: Person, prefer_source_fields: list<string>}
     */
    private function mergePayload(Request $request, bool $requireConfirm = false): array
    {
        $rules = [
            'source_person_id' => ['required', 'string'],
            'target_person_id' => ['required', 'string'],
            'prefer_source_fields' => ['nullable', 'array'],
            'prefer_source_fields.*' => ['string', 'max:40'],
        ];
        if ($requireConfirm) {
            $rules['confirm'] = ['required', 'in:MERGE'];
        }

        $validated = $request->validate($rules);
        $source = Person::query()->where('uuid', $validated['source_person_id'])->firstOrFail();
        $target = Person::query()->where('uuid', $validated['target_person_id'])->firstOrFail();

        return [
            'source' => $source,
            'target' => $target,
            'prefer_source_fields' => array_values($validated['prefer_source_fields'] ?? []),
        ];
    }
}
