<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreEventRequest;
use App\Modules\Events\Http\Requests\UpdateEventRequest;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventAdminController extends ApiController
{
  public function index(Request $request, EventService $service): JsonResponse
  {
    $this->authorize('viewAny', Event::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EventResource::class),
      message: 'Events retrieved.',
    );
  }

  public function show(Event $event): JsonResponse
  {
    $this->authorize('view', $event);

    $event->load(['ministry', 'category', 'venue', 'country', 'region', 'speakers', 'sessions', 'galleryItems', 'resources', 'faqs', 'sponsors', 'registrationQuestions', 'registrationFieldSettings']);
    $event->loadCount('registrations');

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event retrieved.',
    );
  }

  public function store(StoreEventRequest $request, EventService $service): JsonResponse
  {
    $this->authorize('create', Event::class);

    $event = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event created.',
      status: 201,
    );
  }

  public function update(UpdateEventRequest $request, Event $event, EventService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $event = $service->update($event, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event updated.',
    );
  }

  public function publish(Event $event, EventService $service, Request $request): JsonResponse
  {
    $this->authorize('publish', $event);

    $event = $service->publish($event, $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event published.',
    );
  }

  public function unpublish(Event $event, EventService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $event);

    $event = $service->unpublish($event, $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event unpublished.',
    );
  }

  public function archive(Event $event, EventService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $event);

    $event = $service->archive($event, $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($event)],
      message: 'Event archived.',
    );
  }

  public function duplicate(Event $event, EventService $service, Request $request): JsonResponse
  {
    $this->authorize('create', Event::class);

    $clone = $service->duplicate($event, $request->user());

    return $this->responder->success(
      data: ['event' => new EventResource($clone)],
      message: 'Event duplicated.',
      status: 201,
    );
  }

  public function destroy(Event $event, EventService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $event);

    $service->delete($event, $request->user());

    return $this->responder->success(data: null, message: 'Event deleted.');
  }
}
