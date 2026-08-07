<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Enums\CmsAuditEventType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Cms\Models\CmsFormSubmissionEvent;
use App\Modules\Cms\Models\CmsFormSubmissionNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CmsFormSubmissionAdminService implements ServiceContract
{
  public function __construct(
    private readonly FormSubmissionService $submissionService,
    private readonly CmsAuditService $auditService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    return $this->submissionService->paginate($filters);
  }

  public function addNote(CmsFormSubmission $submission, User $actor, string $body): CmsFormSubmissionNote
  {
    $note = CmsFormSubmissionNote::query()->create([
      'submission_id' => $submission->id,
      'author_id' => $actor->id,
      'body' => $body,
    ]);

    $this->auditService->record(
      CmsAuditEventType::Updated,
      'form_submission',
      $submission->id,
      $actor,
      null,
      ['note_added' => true],
    );

    $this->recordEvent(
      $submission,
      $actor,
      'note_added',
      'Internal note added',
      $body,
    );

    return $note->load('author');
  }

  /**
   * @return Collection<int, CmsFormSubmissionNote>
   */
  public function notes(CmsFormSubmission $submission): Collection
  {
    return $submission->notes()->with('author')->latest()->get();
  }

  /**
   * @return Collection<int, CmsFormSubmissionEvent>
   */
  public function events(CmsFormSubmission $submission): Collection
  {
    return $submission->events()->with('actor')->latest()->get();
  }

  public function assign(CmsFormSubmission $submission, ?User $assignee, User $actor): CmsFormSubmission
  {
    $old = $submission->only(['assigned_to']);
    $submission->update(['assigned_to' => $assignee?->id]);
    $this->auditService->record(CmsAuditEventType::Updated, 'form_submission', $submission->id, $actor, $old, $submission->only(['assigned_to']));

    $this->recordEvent(
      $submission,
      $actor,
      $assignee ? 'assigned' : 'unassigned',
      $assignee ? 'Assigned to staff' : 'Unassigned',
      $assignee
        ? ('Assigned to '.($assignee->display_name ?? $assignee->name ?? 'staff'))
        : 'Assignment cleared.',
      ['assigned_to' => $assignee?->id],
    );

    return $submission->fresh(['assignee']);
  }

  public function recordEvent(
    CmsFormSubmission $submission,
    ?User $actor,
    string $eventType,
    string $title,
    ?string $body = null,
    array $meta = [],
  ): CmsFormSubmissionEvent {
    return CmsFormSubmissionEvent::query()->create([
      'submission_id' => $submission->id,
      'actor_id' => $actor?->id,
      'event_type' => $eventType,
      'title' => $title,
      'body' => $body,
      'meta' => $meta,
    ]);
  }

  /**
   * @return Collection<int, CmsFormSubmission>
   */
  public function export(array $filters = []): Collection
  {
    $trashed = $filters['trashed'] ?? null;
    $query = match ($trashed) {
      'only', '1', 1, true, 'true' => CmsFormSubmission::onlyTrashed(),
      'with' => CmsFormSubmission::withTrashed(),
      default => CmsFormSubmission::query(),
    };
    $query->latest();

    if (! empty($filters['type'])) {
      $query->where('type', $filters['type']);
    }

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    return $query->limit(5000)->get();
  }
}
