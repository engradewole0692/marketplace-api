<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\MemberNotificationQueueResource;
use App\Models\MemberNotificationQueue;
use App\Services\Membership\MemberNotificationAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberNotificationQueueController extends ApiController
{
  public function index(Request $request, MemberNotificationAdminService $service): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage', 'members.view']), 403);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), MemberNotificationQueueResource::class),
      message: 'Notification queue retrieved.',
    );
  }

  public function show(Request $request, MemberNotificationQueue $notification): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage', 'members.view']), 403);

    $notification->load(['member']);

    return $this->responder->success(
      data: ['notification' => new MemberNotificationQueueResource($notification)],
      message: 'Notification retrieved.',
    );
  }

  public function markSent(Request $request, MemberNotificationQueue $notification, MemberNotificationAdminService $service): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage']), 403);
    $item = $service->markSent($notification, $request->user());

    return $this->responder->success(data: ['notification' => ['id' => $item->uuid, 'status' => $item->status]], message: 'Marked sent.');
  }

  public function markFailed(Request $request, MemberNotificationQueue $notification, MemberNotificationAdminService $service): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage']), 403);
    $validated = $request->validate(['error' => ['required', 'string', 'max:2000']]);
    $item = $service->markFailed($notification, $validated['error']);

    return $this->responder->success(data: ['notification' => ['id' => $item->uuid, 'status' => $item->status]], message: 'Marked failed.');
  }

  public function retry(Request $request, MemberNotificationQueue $notification, MemberNotificationAdminService $service): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage']), 403);
    $item = $service->retry($notification);

    return $this->responder->success(data: ['notification' => ['id' => $item->uuid, 'status' => $item->status]], message: 'Queued for retry.');
  }

  public function cancel(Request $request, MemberNotificationQueue $notification, MemberNotificationAdminService $service): JsonResponse
  {
    abort_unless($request->user()?->hasAnyPermission(['onboarding.manage', 'members.manage']), 403);
    $item = $service->cancel($notification);

    return $this->responder->success(data: ['notification' => ['id' => $item->uuid, 'status' => $item->status]], message: 'Cancelled.');
  }
}
