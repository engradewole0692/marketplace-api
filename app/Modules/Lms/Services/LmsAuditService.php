<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseAuditLog;

final class LmsAuditService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $old
   * @param  array<string, mixed>|null  $new
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    ?Course $course,
    ?User $actor,
    string $eventType,
    ?string $description = null,
    ?array $old = null,
    ?array $new = null,
    ?array $metadata = null,
  ): CourseAuditLog {
    return CourseAuditLog::query()->create([
      'course_id' => $course?->id,
      'actor_id' => $actor?->id,
      'event_type' => $eventType,
      'description' => $description,
      'old_values' => $old,
      'new_values' => $new,
      'metadata' => $metadata,
      'ip_address' => request()?->ip(),
    ]);
  }
}
