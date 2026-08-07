<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\MemberNotificationQueue;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Aggregates personal Member Workspace data with strict member-owned scoping.
 */
final class MemberPortalWorkspaceService implements ServiceContract
{
  /**
   * @return array<string, mixed>
   */
  public function buildDashboard(Member $member): array
  {
    $member->loadMissing([
      'ministry',
      'country',
      'region',
      'preferredMinistry',
      'contacts',
      'addresses',
      'photoMedia',
      'user',
    ]);

    $userId = (int) $member->user_id;

    $enrollments = Enrollment::query()
      ->where(function ($q) use ($member, $userId): void {
        $q->where('member_id', $member->id);
        if ($userId > 0) {
          $q->orWhere('user_id', $userId);
        }
      })
      ->with(['course:id,uuid,title,slug'])
      ->latest('enrolled_at')
      ->limit(8)
      ->get();

    $certificates = CourseCertificate::query()
      ->where(function ($q) use ($member, $userId): void {
        if ($userId > 0) {
          $q->where('user_id', $userId);
        }
        $q->orWhereHas('enrollment', fn ($enrollment) => $enrollment->where('member_id', $member->id));
      })
      ->with(['course:id,uuid,title,slug', 'enrollment'])
      ->latest('issued_at')
      ->limit(5)
      ->get()
      ->map(fn (CourseCertificate $cert) => [
        'id' => $cert->uuid,
        'certificate_number' => $cert->certificate_number ?? null,
        'issued_at' => $cert->issued_at?->toIso8601String(),
        'course' => $cert->course ? [
          'id' => $cert->course->uuid,
          'title' => $cert->course->title,
          'slug' => $cert->course->slug,
        ] : null,
      ])
      ->values();

    $registrations = EventRegistration::query()
      ->where('member_id', $member->id)
      ->with(['event:id,uuid,title,slug,starts_at,ends_at'])
      ->latest('submitted_at')
      ->limit(8)
      ->get();

    $upcomingEvents = $registrations
      ->filter(fn (EventRegistration $row) => $row->event?->starts_at !== null && $row->event->starts_at->isFuture())
      ->take(5)
      ->values();

    $notifications = $member->notificationQueue()
      ->latest()
      ->limit(50)
      ->get()
      ->reject(fn (MemberNotificationQueue $row) => (bool) data_get($row->payload, 'archived_at'))
      ->take(8)
      ->values();

    $unreadNotifications = $notifications
      ->reject(fn (MemberNotificationQueue $row) => (bool) data_get($row->payload, 'read_at'))
      ->count();

    $prayer = $this->formSubmissionsForMember($member, FormSubmissionType::Prayer, 5);
    $counselling = $this->formSubmissionsForMember($member, FormSubmissionType::Counseling, 5);

    $activeCourses = $enrollments->filter(function (Enrollment $enrollment): bool {
      $status = $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : (string) $enrollment->status;

      return ! in_array($status, ['completed', 'cancelled'], true);
    });

    $completedCourses = $enrollments->filter(function (Enrollment $enrollment): bool {
      $status = $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : (string) $enrollment->status;

      return $status === 'completed' || (float) $enrollment->progress_percent >= 100;
    });

    $avgProgress = $enrollments->avg(fn (Enrollment $e) => (float) $e->progress_percent) ?? 0;

    return [
      'widgets' => [
        'membership_status' => $member->status instanceof \BackedEnum ? $member->status->value : (string) $member->status,
        'membership_number' => $member->membership_number,
        'application_number' => $member->application_number ?? $member->membership_number,
        'ministry' => $member->ministry?->name,
        'preferred_ministry' => $member->preferredMinistry?->name,
        'country' => $member->country?->name,
        'region' => $member->region?->name,
        'profile_completion' => $this->profileCompletion($member),
        'learning_progress' => [
          'active' => $activeCourses->count(),
          'completed' => $completedCourses->count(),
          'avg_percent' => round((float) $avgProgress, 1),
        ],
        'unread_notifications' => $unreadNotifications,
        'upcoming_events' => $upcomingEvents->count(),
        'prayer_open' => $prayer->where('status', '!=', 'closed')->count(),
        'counselling_open' => $counselling->where('status', '!=', 'closed')->count(),
        'certificates_earned' => $certificates->count(),
      ],
      'sections' => [
        'courses' => $enrollments->map(fn (Enrollment $enrollment) => [
          'id' => $enrollment->uuid,
          'progress_percent' => (float) $enrollment->progress_percent,
          'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : $enrollment->status,
          'course' => $enrollment->course ? [
            'id' => $enrollment->course->uuid,
            'title' => $enrollment->course->title,
            'slug' => $enrollment->course->slug,
          ] : null,
        ])->values()->all(),
        'events' => $registrations->map(fn (EventRegistration $registration) => [
          'id' => $registration->uuid,
          'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : $registration->status,
          'registration_number' => $registration->registration_number,
          'event' => $registration->event ? [
            'id' => $registration->event->uuid,
            'title' => $registration->event->title,
            'slug' => $registration->event->slug,
            'starts_at' => $registration->event->starts_at?->toIso8601String(),
            'ends_at' => $registration->event->ends_at?->toIso8601String(),
          ] : null,
        ])->values()->all(),
        'certificates' => $certificates->all(),
        'notifications' => $notifications->map(fn (MemberNotificationQueue $row) => $this->mapNotification($row))->all(),
        'prayer' => $prayer->all(),
        'counselling' => $counselling->all(),
        'activity' => $this->buildActivityFeed($member, 8),
        'announcements' => [],
        'resources' => [],
        'downloads' => [],
        'messages' => [],
        'payments' => [],
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function buildActivityFeed(Member $member, int $limit = 50): array
  {
    $items = collect();

    foreach ($member->timelines()->latest('occurred_at')->limit($limit)->get() as $entry) {
      $items->push([
        'id' => 'timeline-'.($entry->uuid ?? $entry->id),
        'category' => 'membership',
        'event_type' => $entry->event_type instanceof \BackedEnum ? $entry->event_type->value : (string) $entry->event_type,
        'description' => $entry->description,
        'occurred_at' => $entry->occurred_at?->toIso8601String() ?? $entry->created_at?->toIso8601String(),
      ]);
    }

    $userId = (int) $member->user_id;
    foreach (
      Enrollment::query()
        ->where(function ($q) use ($member, $userId): void {
          $q->where('member_id', $member->id);
          if ($userId > 0) {
            $q->orWhere('user_id', $userId);
          }
        })
        ->with('course:id,title')
        ->latest('updated_at')
        ->limit(20)
        ->get() as $enrollment
    ) {
      $items->push([
        'id' => 'learning-'.$enrollment->uuid,
        'category' => 'learning',
        'event_type' => 'course_progress',
        'description' => sprintf(
          'Learning progress on %s (%s%%)',
          $enrollment->course?->title ?? 'course',
          (int) $enrollment->progress_percent,
        ),
        'occurred_at' => $enrollment->updated_at?->toIso8601String(),
      ]);
    }

    foreach (
      EventRegistration::query()
        ->where('member_id', $member->id)
        ->with('event:id,title')
        ->latest('submitted_at')
        ->limit(20)
        ->get() as $registration
    ) {
      $items->push([
        'id' => 'event-'.$registration->uuid,
        'category' => 'events',
        'event_type' => 'event_registration',
        'description' => sprintf(
          'Registered for %s',
          $registration->event?->title ?? 'an event',
        ),
        'occurred_at' => $registration->submitted_at?->toIso8601String() ?? $registration->created_at?->toIso8601String(),
      ]);
    }

    foreach ($member->notificationQueue()->latest()->limit(20)->get() as $notification) {
      if (data_get($notification->payload, 'archived_at')) {
        continue;
      }
      $items->push([
        'id' => 'notification-'.$notification->uuid,
        'category' => 'notifications',
        'event_type' => $notification->template,
        'description' => 'Notification: '.str_replace('_', ' ', (string) $notification->template),
        'occurred_at' => $notification->queued_at?->toIso8601String() ?? $notification->created_at?->toIso8601String(),
      ]);
    }

    foreach ($this->formSubmissionsForMember($member, FormSubmissionType::Prayer, 10) as $row) {
      $items->push([
        'id' => 'prayer-'.$row['id'],
        'category' => 'prayer',
        'event_type' => 'prayer_request',
        'description' => 'Prayer request submitted',
        'occurred_at' => $row['created_at'],
      ]);
    }

    foreach ($this->formSubmissionsForMember($member, FormSubmissionType::Counseling, 10) as $row) {
      $items->push([
        'id' => 'counselling-'.$row['id'],
        'category' => 'counselling',
        'event_type' => 'counselling_request',
        'description' => 'Counselling request submitted',
        'occurred_at' => $row['created_at'],
      ]);
    }

    return $items
      ->sortByDesc(fn (array $row) => $row['occurred_at'] ?? '')
      ->take($limit)
      ->values()
      ->all();
  }

  /**
   * @return Collection<int, array<string, mixed>>
   */
  public function formSubmissionsForMember(Member $member, FormSubmissionType $type, int $limit = 50): Collection
  {
    $email = strtolower(trim((string) $member->email));
    if ($email === '') {
      return collect();
    }

    return CmsFormSubmission::query()
      ->where('type', $type->value)
      ->whereRaw('LOWER(submitter_email) = ?', [$email])
      ->latest()
      ->limit($limit)
      ->get()
      ->map(fn (CmsFormSubmission $submission) => [
        'id' => $submission->uuid,
        'type' => $submission->type instanceof \BackedEnum ? $submission->type->value : $submission->type,
        'status' => $submission->status instanceof \BackedEnum ? $submission->status->value : $submission->status,
        'payload' => $submission->payload,
        'created_at' => $submission->created_at?->toIso8601String(),
        'updated_at' => $submission->updated_at?->toIso8601String(),
      ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function mapNotification(MemberNotificationQueue $row): array
  {
    $payload = is_array($row->payload) ? $row->payload : [];

    return [
      'id' => $row->uuid,
      'channel' => $row->channel,
      'template' => $row->template,
      'status' => $row->status,
      'payload' => $payload,
      'read' => (bool) ($payload['read_at'] ?? false),
      'archived' => (bool) ($payload['archived_at'] ?? false),
      'queued_at' => $row->queued_at?->toIso8601String(),
      'scheduled_at' => $row->scheduled_at?->toIso8601String(),
      'sent_at' => $row->sent_at?->toIso8601String(),
    ];
  }

  public function markNotificationRead(Member $member, string $notificationUuid): MemberNotificationQueue
  {
    $item = $this->ownedNotification($member, $notificationUuid);
    $payload = is_array($item->payload) ? $item->payload : [];
    $payload['read_at'] = now()->toIso8601String();
    $item->payload = $payload;
    $item->save();

    return $item;
  }

  public function markAllNotificationsRead(Member $member): int
  {
    $count = 0;
    foreach ($member->notificationQueue()->latest()->limit(200)->get() as $item) {
      $payload = is_array($item->payload) ? $item->payload : [];
      if (! empty($payload['archived_at']) || ! empty($payload['read_at'])) {
        continue;
      }
      $payload['read_at'] = now()->toIso8601String();
      $item->payload = $payload;
      $item->save();
      $count++;
    }

    return $count;
  }

  public function archiveNotification(Member $member, string $notificationUuid): MemberNotificationQueue
  {
    $item = $this->ownedNotification($member, $notificationUuid);
    $payload = is_array($item->payload) ? $item->payload : [];
    $payload['archived_at'] = now()->toIso8601String();
    $payload['read_at'] = $payload['read_at'] ?? now()->toIso8601String();
    $item->payload = $payload;
    $item->save();

    return $item;
  }

  public function deleteNotification(Member $member, string $notificationUuid): void
  {
    $item = $this->ownedNotification($member, $notificationUuid);
    $item->cancelled_at = now();
    $item->status = 'cancelled';
    $payload = is_array($item->payload) ? $item->payload : [];
    $payload['deleted_at'] = now()->toIso8601String();
    $item->payload = $payload;
    $item->save();
  }

  private function ownedNotification(Member $member, string $notificationUuid): MemberNotificationQueue
  {
    return MemberNotificationQueue::query()
      ->where('member_id', $member->id)
      ->where('uuid', $notificationUuid)
      ->firstOrFail();
  }

  private function profileCompletion(Member $member): int
  {
    $fields = [
      $member->first_name,
      $member->last_name,
      $member->email,
      $member->phone,
      $member->occupation,
      $member->city,
      $member->biography,
      $member->photo_path ?: $member->photo_media_id,
      $member->country_id,
      $member->ministry_id ?: $member->preferred_ministry_id,
    ];
    $filled = collect($fields)->filter(fn ($value) => $value !== null && $value !== '')->count();

    return (int) round(($filled / max(count($fields), 1)) * 100);
  }
}
