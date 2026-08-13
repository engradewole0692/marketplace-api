<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreRegistrationRequest;
use App\Modules\Events\Http\Resources\EventRegistrationResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\NotificationService;
use App\Modules\Events\Services\RegistrationService;
use App\Modules\Events\Support\PublicEventAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class PublicRegistrationController extends ApiController
{
  public function store(StoreRegistrationRequest $request, RegistrationService $service, NotificationService $notificationService): JsonResponse
  {
    $event = Event::query()->findOrFail($request->validated('event_id'));
    PublicEventAccess::ensureRegistrationAllowed($event);

    $result = $service->register($request->validated(), $request->user());

    try {
      $notificationService->sendRegistrationNotifications($result['registration'], $result['created']);
    } catch (\Throwable $exception) {
      Log::warning('Event registration notification dispatch failed', [
        'registration_id' => $result['registration']->id,
        'error' => $exception->getMessage(),
      ]);
    }

    return $this->responder->success(
      data: ['registration' => new EventRegistrationResource($result['registration'])],
      message: $result['created'] ? 'Registration submitted.' : 'Registration updated.',
      status: $result['created'] ? 201 : 200,
    );
  }
}
