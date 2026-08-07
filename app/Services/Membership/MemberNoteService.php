<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberNote;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class MemberNoteService implements ServiceContract
{
  public function __construct(
    private readonly MemberAuditService $auditService,
    private readonly MemberTimelineService $timelineService,
  ) {}

  /**
   * @return LengthAwarePaginator<int, MemberNote>
   */
  public function paginate(Member $member, array $filters = []): LengthAwarePaginator
  {
    $query = $member->notes()->with('author');

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where('body', 'like', "%{$search}%");
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->orderByDesc('created_at')->paginate($perPage);
  }

  public function create(Member $member, string $body, User $actor, bool $isPrivate = true): MemberNote
  {
    $note = $member->notes()->create([
      'author_id' => $actor->id,
      'body' => $body,
      'is_private' => $isPrivate,
    ]);

    $this->auditService->record(
      MemberAuditEventType::NoteCreated,
      $member,
      $actor,
      metadata: ['note_id' => $note->id],
    );

    $this->timelineService->record(
      $member,
      MemberTimelineEventType::NoteAdded,
      'Administrative note added.',
      $actor,
      ['note_id' => $note->id],
    );

    return $note->load('author');
  }

  public function delete(MemberNote $note, User $actor): void
  {
    $member = $note->member;
    $note->delete();

    $this->auditService->record(
      MemberAuditEventType::NoteDeleted,
      $member,
      $actor,
      metadata: ['note_id' => $note->id],
    );
  }
}
