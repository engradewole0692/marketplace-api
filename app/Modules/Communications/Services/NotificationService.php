<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\User;
use App\Modules\Communications\Models\PlatformNotification;
use Illuminate\Support\Str;

final class NotificationService
{
  /**
   * Send a notification to a specific user.
   */
  public function sendToUser(
    User $recipient,
    string $title,
    string $body,
    string $type = 'info',
    ?string $actionUrl = null,
    ?string $relatedType = null,
    ?string $relatedId = null,
    ?User $sender = null,
  ): PlatformNotification {
    return PlatformNotification::query()->create([
      'uuid' => Str::uuid()->toString(),
      'user_id' => $recipient->id,
      'type' => $type,
      'title' => $title,
      'body' => $body,
      'action_url' => $actionUrl,
      'related_type' => $relatedType,
      'related_id' => $relatedId,
      'sender_id' => $sender?->id,
      'is_read' => false,
    ]);
  }

  /**
   * Send a notification to all users matching target criteria.
   */
  public function sendBulk(
    string $title,
    string $body,
    string $targetType = 'all',
    array $options = [],
    ?User $sender = null,
  ): PlatformNotification {
    return PlatformNotification::query()->create([
      'uuid' => Str::uuid()->toString(),
      'user_id' => null,
      'target_type' => $targetType,
      'role_slug' => $options['role_slug'] ?? null,
      'country_id' => $options['country_id'] ?? null,
      'region_id' => $options['region_id'] ?? null,
      'ministry_id' => $options['ministry_id'] ?? null,
      'type' => $options['type'] ?? 'info',
      'title' => $title,
      'body' => $body,
      'action_url' => $options['action_url'] ?? null,
      'related_type' => $options['related_type'] ?? null,
      'related_id' => $options['related_id'] ?? null,
      'sender_id' => $sender?->id,
      'is_read' => false,
    ]);
  }

  /**
   * Fetch unread notifications for a user (personal + role + broadcast).
   *
   * @return array{notifications: \Illuminate\Contracts\Pagination\LengthAwarePaginator, unread_count: int}
   */
  public function forUser(User $user, int $perPage = 20): array
  {
    $roleSlug = $user->roles?->first()?->slug ?? null;

    $query = PlatformNotification::query()
      ->whereNull('deleted_at')
      ->where(function ($q) use ($user, $roleSlug): void {
        // Personal
        $q->where('user_id', $user->id);
        // Role-based
        if ($roleSlug !== null) {
          $q->orWhere('role_slug', $roleSlug);
        }
        // Broadcast (all / members / visitors / staff)
        $q->orWhere('target_type', 'all');
      })
      ->orderByDesc('created_at');

    $unreadCount = (clone $query)->where(function ($q) use ($user): void {
      $q->where(function ($inner) use ($user): void {
        $inner->where('user_id', $user->id)->where('is_read', false);
      })->orWhere(function ($inner): void {
        $inner->whereNull('user_id')->where('is_read', false);
      });
    })->count();

    return [
      'notifications' => $query->paginate($perPage),
      'unread_count' => $unreadCount,
    ];
  }

  public function markRead(PlatformNotification $notification, User $user): void
  {
    if ($notification->user_id === $user->id) {
      $notification->is_read = true;
      $notification->read_at = now();
      $notification->save();
    }
  }

  public function markAllReadForUser(User $user): int
  {
    return PlatformNotification::query()
      ->where('user_id', $user->id)
      ->where('is_read', false)
      ->update(['is_read' => true, 'read_at' => now()]);
  }

  public function unreadCount(User $user): int
  {
    return PlatformNotification::query()
      ->where('user_id', $user->id)
      ->where('is_read', false)
      ->count();
  }
}
