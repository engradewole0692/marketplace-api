<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\PlatformNotification;
use App\Modules\Communications\Services\NotificationService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class NotificationController extends ApiController
{
  public function index(Request $request, NotificationService $service): JsonResponse
  {
    $user = $request->user();
    $result = $service->forUser($user, (int) $request->query('per_page', 20));

    return $this->responder->success(
      data: [
        'notifications' => PaginatedResponseBuilder::fromPaginator(
          $result['notifications'],
          fn ($n) => $this->transform($n),
        ),
        'unread_count' => $result['unread_count'],
      ],
      message: 'Notifications retrieved.',
    );
  }

  public function unreadCount(Request $request, NotificationService $service): JsonResponse
  {
    return $this->responder->success(
      data: ['unread_count' => $service->unreadCount($request->user())],
      message: 'Unread count retrieved.',
    );
  }

  public function markRead(Request $request, NotificationService $service, string $uuid): JsonResponse
  {
    $notification = PlatformNotification::query()->where('uuid', $uuid)->firstOrFail();
    $service->markRead($notification, $request->user());

    return $this->responder->success(message: 'Notification marked as read.');
  }

  public function markAllRead(Request $request, NotificationService $service): JsonResponse
  {
    $count = $service->markAllReadForUser($request->user());

    return $this->responder->success(
      data: ['marked' => $count],
      message: 'All notifications marked as read.',
    );
  }

  // Admin: send a notification
  public function send(Request $request, NotificationService $service): JsonResponse
  {
    $this->authorize('manage', PlatformNotification::class);

    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'body' => ['required', 'string'],
      'type' => ['nullable', 'string', 'in:info,success,warning,alert,message,event,lms,counselling,announcement'],
      'target_type' => ['nullable', 'string', 'in:all,members,visitors,staff,admins'],
      'user_id' => ['nullable', 'string'],
      'role_slug' => ['nullable', 'string'],
      'country_id' => ['nullable', 'integer'],
      'region_id' => ['nullable', 'integer'],
      'ministry_id' => ['nullable', 'integer'],
      'action_url' => ['nullable', 'string', 'max:500'],
    ]);

    $actor = $request->user();

    if (! empty($validated['user_id'])) {
      $recipient = \App\Models\User::query()->where('uuid', $validated['user_id'])->firstOrFail();
      $notification = $service->sendToUser(
        $recipient,
        $validated['title'],
        $validated['body'],
        $validated['type'] ?? 'info',
        $validated['action_url'] ?? null,
        null,
        null,
        $actor,
      );
    } else {
      $notification = $service->sendBulk(
        $validated['title'],
        $validated['body'],
        $validated['target_type'] ?? 'all',
        [
          'type' => $validated['type'] ?? 'info',
          'role_slug' => $validated['role_slug'] ?? null,
          'country_id' => $validated['country_id'] ?? null,
          'region_id' => $validated['region_id'] ?? null,
          'ministry_id' => $validated['ministry_id'] ?? null,
          'action_url' => $validated['action_url'] ?? null,
        ],
        $actor,
      );
    }

    return $this->responder->success(
      data: ['notification' => $this->transform($notification)],
      message: 'Notification sent.',
    );
  }

  private function transform(PlatformNotification $n): array
  {
    return [
      'id' => $n->uuid,
      'type' => $n->type,
      'title' => $n->title,
      'body' => $n->body,
      'action_url' => $n->action_url,
      'is_read' => (bool) $n->is_read,
      'read_at' => $n->read_at?->toIso8601String(),
      'related_type' => $n->related_type,
      'related_id' => $n->related_id,
      'created_at' => $n->created_at?->toIso8601String(),
    ];
  }
}
