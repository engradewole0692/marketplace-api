<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreSessionRequest;
use App\Modules\Events\Http\Resources\EventSessionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSession;
use App\Modules\Events\Services\SessionService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventSessionAdminController extends ApiController
{
  public function index(Event $event): JsonResponse
  {
    $this->authorize('view', $event);

    $sessions = $event->sessions()->with('speaker')->orderBy('starts_at')->paginate(50);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($sessions, EventSessionResource::class),
      message: 'Sessions retrieved.',
    );
  }

  public function store(StoreSessionRequest $request, Event $event, SessionService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $session = $service->create($event, $request->validated());

    return $this->responder->success(
      data: ['session' => new EventSessionResource($session)],
      message: 'Session created.',
      status: 201,
    );
  }

  public function update(StoreSessionRequest $request, EventSession $session, SessionService $service): JsonResponse
  {
    $session->loadMissing('event');
    $this->authorize('update', $session->event);

    $updated = $service->update($session, $request->validated());

    return $this->responder->success(
      data: ['session' => new EventSessionResource($updated)],
      message: 'Session updated.',
    );
  }

  public function destroy(EventSession $session, SessionService $service): JsonResponse
  {
    $session->loadMissing('event');
    $this->authorize('update', $session->event);

    $service->delete($session);

    return $this->responder->success(data: null, message: 'Session deleted.');
  }
}
