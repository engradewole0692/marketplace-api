<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Http\Resources\CmsAdminNotificationResource;
use App\Modules\Cms\Services\CmsNotificationService;
use App\Modules\Cms\Models\CmsAdminNotification;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsNotificationController extends ApiController
{
  public function index(Request $request, CmsNotificationService $service): JsonResponse
  {
    $paginator = $service->paginateForUser($request->user(), $request->query());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, CmsAdminNotificationResource::class),
      message: 'Notifications retrieved.',
    );
  }

  public function unreadCount(Request $request, CmsNotificationService $service): JsonResponse
  {
    return $this->responder->success(
      data: ['count' => $service->unreadCount($request->user())],
      message: 'Unread count retrieved.',
    );
  }

  public function markRead(Request $request, CmsAdminNotification $notification, CmsNotificationService $service): JsonResponse
  {
    $service->markRead($notification, $request->user());

    return $this->responder->success(message: 'Notification marked as read.');
  }

  public function markAllRead(Request $request, CmsNotificationService $service): JsonResponse
  {
    $count = $service->markAllRead($request->user());

    return $this->responder->success(
      data: ['updated' => $count],
      message: 'All notifications marked as read.',
    );
  }
}
