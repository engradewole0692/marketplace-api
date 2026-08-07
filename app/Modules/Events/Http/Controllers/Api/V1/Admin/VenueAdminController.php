<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreVenueRequest;
use App\Modules\Events\Http\Requests\UpdateVenueRequest;
use App\Modules\Events\Http\Resources\VenueResource;
use App\Modules\Events\Models\Venue;
use App\Modules\Events\Services\VenueService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueAdminController extends ApiController
{
  public function index(Request $request, VenueService $service): JsonResponse
  {
    $this->authorize('viewAny', Venue::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), VenueResource::class),
      message: 'Venues retrieved.',
    );
  }

  public function show(Venue $venue): JsonResponse
  {
    $this->authorize('view', $venue);

    return $this->responder->success(
      data: ['venue' => new VenueResource($venue->load(['country', 'region']))],
      message: 'Venue retrieved.',
    );
  }

  public function store(StoreVenueRequest $request, VenueService $service): JsonResponse
  {
    $this->authorize('create', Venue::class);

    $venue = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['venue' => new VenueResource($venue)],
      message: 'Venue created.',
      status: 201,
    );
  }

  public function update(UpdateVenueRequest $request, Venue $venue, VenueService $service): JsonResponse
  {
    $this->authorize('update', $venue);

    $venue = $service->update($venue, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['venue' => new VenueResource($venue)],
      message: 'Venue updated.',
    );
  }

  public function destroy(Venue $venue, VenueService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $venue);

    $service->delete($venue, $request->user());

    return $this->responder->success(data: null, message: 'Venue deleted.');
  }
}
