<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsNotificationType;
use App\Modules\Cms\Models\CmsAdminNotification;
use App\Modules\Cms\Models\CmsFormSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CmsNotificationService implements ServiceContract
{
  /**
   * @param  array<string, mixed>  $data
   */
  public function notifyAdminsWithPermission(
    string $permission,
    CmsNotificationType $type,
    string $title,
    string $message,
    array $data = [],
  ): void {
    $users = User::query()
      ->where('status', 'active')
      ->get()
      ->filter(fn (User $user): bool => $user->hasPermission($permission));

    foreach ($users as $user) {
      CmsAdminNotification::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'data' => $data,
      ]);
    }
  }

  public function notifyFormSubmission(CmsFormSubmission $submission): void
  {
    $type = $submission->type->value;
    $this->notifyAdminsWithPermission(
      'cms.manage',
      CmsNotificationType::FormSubmission,
      'New '.$type.' submission',
      sprintf('A new %s form was submitted by %s.', $type, $submission->submitter_name ?? 'a visitor'),
      ['submission_id' => $submission->uuid, 'type' => $type],
    );
  }

  public function paginateForUser(User $user, array $filters = []): LengthAwarePaginator
  {
    $query = CmsAdminNotification::query()
      ->where('user_id', $user->id)
      ->latest();

    if (isset($filters['unread_only']) && filter_var($filters['unread_only'], FILTER_VALIDATE_BOOLEAN)) {
      $query->whereNull('read_at');
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function unreadCount(User $user): int
  {
    return CmsAdminNotification::query()
      ->where('user_id', $user->id)
      ->whereNull('read_at')
      ->count();
  }

  public function markRead(CmsAdminNotification $notification, User $user): CmsAdminNotification
  {
    abort_unless($notification->user_id === $user->id, 403);
    $notification->update(['read_at' => now()]);

    return $notification->fresh();
  }

  public function markAllRead(User $user): int
  {
    return CmsAdminNotification::query()
      ->where('user_id', $user->id)
      ->whereNull('read_at')
      ->update(['read_at' => now()]);
  }
}
