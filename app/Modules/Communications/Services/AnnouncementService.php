<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\User;
use App\Modules\Communications\Models\PlatformAnnouncement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class AnnouncementService
{
  public function __construct(
    private readonly NotificationService $notifications,
  ) {}

  public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
  {
    $query = PlatformAnnouncement::query()->latest();

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['target_audience'])) {
      $query->where('target_audience', $filters['target_audience']);
    }
    if (isset($filters['show_on_public'])) {
      $query->where('show_on_public', (bool) $filters['show_on_public']);
    }

    return $query->paginate($perPage);
  }

  public function create(array $data, User $actor): PlatformAnnouncement
  {
    $announcement = PlatformAnnouncement::query()->create([
      'uuid' => Str::uuid()->toString(),
      'title' => $data['title'],
      'content' => $data['content'],
      'image_path' => $data['image_path'] ?? null,
      'status' => $data['status'] ?? 'draft',
      'target_audience' => $data['target_audience'] ?? 'all',
      'show_on_public' => (bool) ($data['show_on_public'] ?? false),
      'send_email' => (bool) ($data['send_email'] ?? false),
      'send_notification' => (bool) ($data['send_notification'] ?? true),
      'target_countries' => $data['target_countries'] ?? null,
      'target_regions' => $data['target_regions'] ?? null,
      'target_ministries' => $data['target_ministries'] ?? null,
      'target_roles' => $data['target_roles'] ?? null,
      'publish_at' => $data['publish_at'] ?? null,
      'expires_at' => $data['expires_at'] ?? null,
      'created_by' => $actor->id,
    ]);

    if ($announcement->status === 'published') {
      $this->dispatch($announcement, $actor);
    }

    return $announcement;
  }

  public function update(PlatformAnnouncement $announcement, array $data, User $actor): PlatformAnnouncement
  {
    $wasPublished = $announcement->status === 'published';

    $announcement->fill([
      'title' => $data['title'] ?? $announcement->title,
      'content' => $data['content'] ?? $announcement->content,
      'image_path' => array_key_exists('image_path', $data) ? $data['image_path'] : $announcement->image_path,
      'status' => $data['status'] ?? $announcement->status,
      'target_audience' => $data['target_audience'] ?? $announcement->target_audience,
      'show_on_public' => isset($data['show_on_public']) ? (bool) $data['show_on_public'] : $announcement->show_on_public,
      'send_email' => isset($data['send_email']) ? (bool) $data['send_email'] : $announcement->send_email,
      'send_notification' => isset($data['send_notification']) ? (bool) $data['send_notification'] : $announcement->send_notification,
      'target_countries' => array_key_exists('target_countries', $data) ? $data['target_countries'] : $announcement->target_countries,
      'target_regions' => array_key_exists('target_regions', $data) ? $data['target_regions'] : $announcement->target_regions,
      'target_ministries' => array_key_exists('target_ministries', $data) ? $data['target_ministries'] : $announcement->target_ministries,
      'target_roles' => array_key_exists('target_roles', $data) ? $data['target_roles'] : $announcement->target_roles,
      'publish_at' => array_key_exists('publish_at', $data) ? $data['publish_at'] : $announcement->publish_at,
      'expires_at' => array_key_exists('expires_at', $data) ? $data['expires_at'] : $announcement->expires_at,
    ]);
    $announcement->save();

    // If transitioning to published for the first time, dispatch.
    if (! $wasPublished && $announcement->status === 'published') {
      $this->publish($announcement, $actor);
    }

    return $announcement;
  }

  public function publish(PlatformAnnouncement $announcement, User $actor): PlatformAnnouncement
  {
    $announcement->status = 'published';
    $announcement->published_by = $actor->id;
    $announcement->published_at = now();
    $announcement->save();

    $this->dispatch($announcement, $actor);

    return $announcement;
  }

  public function delete(PlatformAnnouncement $announcement): void
  {
    $announcement->delete();
  }

  /**
   * Dispatch in-app notification for an announcement.
   */
  private function dispatch(PlatformAnnouncement $announcement, User $actor): void
  {
    if (! $announcement->send_notification) {
      return;
    }

    $this->notifications->sendBulk(
      title: $announcement->title,
      body: mb_substr(strip_tags($announcement->content), 0, 300),
      targetType: $announcement->target_audience,
      options: [
        'type' => 'announcement',
        'related_type' => 'announcement',
        'related_id' => $announcement->uuid,
        'action_url' => '/announcements/'.$announcement->uuid,
      ],
      sender: $actor,
    );
  }

  /**
   * Public-facing active announcements.
   */
  public function publicActive(int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return PlatformAnnouncement::query()
      ->active()
      ->where('show_on_public', true)
      ->orderByDesc('published_at')
      ->limit($limit)
      ->get();
  }
}
