<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\Member;
use App\Models\User;
use App\Modules\Communications\Jobs\SendBulkEmailBatchJob;
use App\Modules\Communications\Models\BulkEmailJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BulkEmailService
{
  public function paginate(int $perPage = 20): LengthAwarePaginator
  {
    return BulkEmailJob::query()->with('creator:id,uuid,name,email')->latest()->paginate($perPage);
  }

  /**
   * Build recipient query from filters (does NOT execute — returns builder for counting or iteration).
   *
   * @param  array<string, mixed>  $filters
   */
  public function buildRecipientQuery(array $filters): Builder
  {
    $query = User::query()
      ->whereNotNull('email')
      ->where('email', '!=', '')
      ->where('status', '!=', 'inactive');

    // Audience type
    if (! empty($filters['audience'])) {
      match ($filters['audience']) {
        'visitors' => $query->where('type', 'visitor'),
        'members' => $query->whereHas('member', fn ($q) => $q->where('status', 'active')),
        'staff' => $query->whereHas('roles', fn ($q) => $q->where('slug', 'staff')),
        'admins' => $query->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'super_admin'])),
        default => null,
      };
    }

    // Country filter
    if (! empty($filters['country_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']));
    }

    // Role filter
    if (! empty($filters['role_slug'])) {
      $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role_slug']));
    }

    // Ministry filter
    if (! empty($filters['ministry_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('ministry_id', $filters['ministry_id']));
    }

    // Event participant filter
    if (! empty($filters['event_id'])) {
      $query->whereHas('eventRegistrations', fn ($q) => $q->where('event_id', $filters['event_id']));
    }

    // Course enrolled filter
    if (! empty($filters['course_id'])) {
      $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $filters['course_id']));
    }

    return $query->select('id', 'uuid', 'name', 'email');
  }

  /**
   * Count estimated recipients without creating the job.
   */
  public function estimateCount(array $filters): int
  {
    return $this->buildRecipientQuery($filters)->count();
  }

  /**
   * Create a BulkEmailJob (status=queued), enqueue a batch job.
   */
  public function create(array $data, User $actor): BulkEmailJob
  {
    $filters = $data['recipient_filters'] ?? [];
    $count = $this->estimateCount($filters);

    $job = BulkEmailJob::query()->create([
      'uuid' => Str::uuid()->toString(),
      'subject' => $data['subject'],
      'html_body' => $data['html_body'],
      'text_body' => $data['text_body'] ?? null,
      'from_name' => $data['from_name'] ?? null,
      'from_email' => $data['from_email'] ?? null,
      'recipient_filters' => $filters,
      'estimated_count' => $count,
      'status' => 'queued',
      'created_by' => $actor->id,
      'queued_at' => now(),
    ]);

    // Enqueue async batch
    dispatch(new SendBulkEmailBatchJob($job->id));

    return $job;
  }

  public function cancel(BulkEmailJob $job): void
  {
    if (! in_array($job->status, ['queued', 'draft'], true)) {
      abort(422, 'Only queued or draft jobs can be cancelled.');
    }

    $job->status = 'cancelled';
    $job->save();
  }
}
