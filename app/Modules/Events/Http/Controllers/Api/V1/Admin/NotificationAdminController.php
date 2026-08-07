<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\SendAnnouncementRequest;
use App\Modules\Events\Http\Resources\EventNotificationTemplateResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventNotificationTemplate;
use App\Modules\Events\Services\NotificationService;
use App\Modules\Events\Support\UuidResolver;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationAdminController extends ApiController
{
  public function templates(Request $request, NotificationService $service): JsonResponse
  {
    $this->authorize('viewAny', EventNotificationTemplate::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->templates($request->query()), EventNotificationTemplateResource::class),
      message: 'Notification templates retrieved.',
    );
  }

  public function storeTemplate(Request $request, NotificationService $service): JsonResponse
  {
    $this->authorize('create', EventNotificationTemplate::class);

    UuidResolver::resolve($request, ['event_id' => Event::class]);

    $validated = $request->validate([
      'event_id' => ['nullable', 'integer', 'exists:events,id'],
      'name' => ['required', 'string', 'max:255'],
      'trigger' => ['required', 'string', 'max:80'],
      'channel' => ['required', 'string', 'max:40'],
      'subject' => ['nullable', 'string', 'max:255'],
      'body' => ['required', 'string'],
      'is_active' => ['boolean'],
    ]);

    $template = $service->createTemplate($validated, $request->user());

    return $this->responder->success(
      data: ['template' => new EventNotificationTemplateResource($template)],
      message: 'Notification template created.',
      status: 201,
    );
  }

  public function sendAnnouncement(SendAnnouncementRequest $request, NotificationService $service): JsonResponse
  {
    $this->authorize('create', EventNotificationTemplate::class);

    $result = $service->sendAnnouncement($request->validated(), $request->user());

    return $this->responder->success(
      data: ['result' => $result],
      message: 'Announcement processed.',
    );
  }
}
