<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\BulkMemberAction;
use App\Enums\MemberApprovalStatus;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberStatus;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberStatusTransition;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class MemberManagementService implements ServiceContract
{
  public function __construct(
    private readonly MembershipNumberGeneratorService $numberGenerator,
    private readonly MemberAuditService $auditService,
    private readonly MemberTimelineService $timelineService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = $this->buildFilteredQuery($filters);

    $sort = (string) ($filters['sort'] ?? 'created_at');
    $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $allowedSorts = ['created_at', 'membership_number', 'first_name', 'last_name', 'status', 'joined_at'];

    if (! in_array($sort, $allowedSorts, true)) {
      $sort = 'created_at';
    }

    $query->orderBy($sort, $direction);

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function buildFilteredQuery(array $filters = []): Builder
  {
    $query = Member::query()->with(['tags', 'creator', 'photoMedia']);

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder
          ->where('membership_number', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%")
          ->orWhere('phone', 'like', "%{$search}%")
          ->orWhere('first_name', 'like', "%{$search}%")
          ->orWhere('last_name', 'like', "%{$search}%")
          ->orWhere('display_name', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['status'])) {
      $status = $filters['status'];
      if (is_string($status) && str_contains($status, ',')) {
        $query->whereIn('status', array_values(array_filter(array_map('trim', explode(',', $status)))));
      } else {
        $query->where('status', $status);
      }
    }

    if (! empty($filters['status_in']) && is_array($filters['status_in'])) {
      $query->whereIn('status', $filters['status_in']);
    }

    if (! empty($filters['approval_status'])) {
      $query->where('approval_status', $filters['approval_status']);
    }

    if (! empty($filters['country_id'])) {
      $query->where('country_id', $filters['country_id']);
    }

    if (! empty($filters['region_id'])) {
      $query->where('region_id', $filters['region_id']);
    }

    if (! empty($filters['ministry_id'])) {
      $query->where('ministry_id', $filters['ministry_id']);
    }

    if (! empty($filters['marketplace_sector'])) {
      $query->where('marketplace_sector', $filters['marketplace_sector']);
    }

    if (! empty($filters['joined_from'])) {
      $query->whereDate('joined_at', '>=', $filters['joined_from']);
    }

    if (! empty($filters['joined_to'])) {
      $query->whereDate('joined_at', '<=', $filters['joined_to']);
    }

    if (! empty($filters['tag'])) {
      $query->whereHas('tags', fn ($q) => $q->where('slug', $filters['tag']));
    }

    if (! empty($filters['trashed']) && $filters['trashed'] === 'only') {
      $query->onlyTrashed();
    } elseif (! empty($filters['trashed']) && $filters['trashed'] === 'with') {
      $query->withTrashed();
    }

    return $query;
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function exportCount(array $filters = []): int
  {
    return $this->buildFilteredQuery($filters)->count();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function recordExportAudit(array $filters, User $actor, int $count): void
  {
    $member = $this->buildFilteredQuery($filters)->first();

    if ($member === null) {
      return;
    }

    $this->auditService->record(
      MemberAuditEventType::MembersExported,
      $member,
      $actor,
      metadata: ['filters' => Arr::only($filters, [
        'search', 'status', 'approval_status', 'country_id', 'region_id', 'ministry_id',
        'marketplace_sector', 'joined_from', 'joined_to', 'tag', 'trashed',
      ]), 'count' => $count],
    );
  }

  /**
   * Create a member from the public website application form (no authenticated actor).
   *
   * @param  array<string, mixed>  $data
   */
  public function createFromPublicApplication(array $data): Member
  {
    return DB::transaction(function () use ($data): Member {
      $member = Member::query()->create([
        'membership_number' => $this->numberGenerator->generate(),
        'application_number' => $data['application_number'] ?? null,
        'application_tracking_token' => $data['application_tracking_token'] ?? null,
        'title' => $data['title'] ?? null,
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'] ?? null,
        'last_name' => $data['last_name'],
        'display_name' => $data['display_name'] ?? null,
        'gender' => $data['gender'] ?? null,
        'date_of_birth' => $data['date_of_birth'] ?? null,
        'phone' => $data['phone'] ?? null,
        'alternate_phone' => $data['alternate_phone'] ?? null,
        'email' => $data['email'] ?? null,
        'occupation' => $data['occupation'] ?? null,
        'organization' => $data['organization'] ?? null,
        'marketplace_sector' => $data['marketplace_sector'] ?? null,
        'skills' => $data['skills'] ?? null,
        'languages' => $data['languages'] ?? null,
        'biography' => $data['biography'] ?? null,
        'profession' => $data['profession'] ?? null,
        'city' => $data['city'] ?? null,
        'state' => $data['state'] ?? null,
        'church_name' => $data['church_name'] ?? null,
        'church_address' => $data['church_address'] ?? null,
        'years_of_experience' => $data['years_of_experience'] ?? null,
        'years_in_faith' => $data['years_in_faith'] ?? null,
        'ministry_interests' => $data['ministry_interests'] ?? null,
        'gifts' => $data['gifts'] ?? null,
        'references' => $data['references'] ?? null,
        'education' => $data['education'] ?? null,
        'availability' => $data['availability'] ?? null,
        'preferred_ministry_id' => $data['preferred_ministry_id'] ?? null,
        'country_id' => $data['country_id'] ?? null,
        'region_id' => $data['region_id'] ?? null,
        'ministry_id' => $data['ministry_id'] ?? null,
        'status' => $data['status'] ?? MemberStatus::ApplicationSubmitted->value,
        'approval_status' => $data['approval_status'] ?? MemberApprovalStatus::Pending,
        'joined_at' => $data['joined_at'] ?? now()->toDateString(),
        'user_id' => $data['user_id'] ?? null,
        'created_by' => null,
        'updated_by' => null,
      ]);

      $this->syncRelations($member, $data);

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::ApplicationSubmitted,
        MemberTimelineEventType::ApplicationSubmitted,
        $member,
        'Membership application submitted via public website.',
        null,
        null,
        ['membership_number' => $member->membership_number, 'source' => 'public_website'],
      );

      return $member->fresh(['tags', 'contacts', 'addresses']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Member
  {
    return DB::transaction(function () use ($data, $actor): Member {
      $member = Member::query()->create([
        'membership_number' => $this->numberGenerator->generate(),
        'title' => $data['title'] ?? null,
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'] ?? null,
        'last_name' => $data['last_name'],
        'display_name' => $data['display_name'] ?? null,
        'gender' => $data['gender'] ?? null,
        'date_of_birth' => $data['date_of_birth'] ?? null,
        'phone' => $data['phone'] ?? null,
        'alternate_phone' => $data['alternate_phone'] ?? null,
        'email' => $data['email'] ?? null,
        'occupation' => $data['occupation'] ?? null,
        'organization' => $data['organization'] ?? null,
        'marketplace_sector' => $data['marketplace_sector'] ?? null,
        'skills' => $data['skills'] ?? null,
        'languages' => $data['languages'] ?? null,
        'biography' => $data['biography'] ?? null,
        'profession' => $data['profession'] ?? null,
        'city' => $data['city'] ?? null,
        'state' => $data['state'] ?? null,
        'church_name' => $data['church_name'] ?? null,
        'church_address' => $data['church_address'] ?? null,
        'years_of_experience' => $data['years_of_experience'] ?? null,
        'years_in_faith' => $data['years_in_faith'] ?? null,
        'ministry_interests' => $data['ministry_interests'] ?? null,
        'gifts' => $data['gifts'] ?? null,
        'references' => $data['references'] ?? null,
        'education' => $data['education'] ?? null,
        'availability' => $data['availability'] ?? null,
        'preferred_ministry_id' => $data['preferred_ministry_id'] ?? null,
        'country_id' => $data['country_id'] ?? null,
        'region_id' => $data['region_id'] ?? null,
        'ministry_id' => $data['ministry_id'] ?? null,
        'status' => $data['status'] ?? config('membership.default_status'),
        'approval_status' => $data['approval_status'] ?? MemberApprovalStatus::Pending,
        'joined_at' => $data['joined_at'] ?? null,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
      ]);

      $this->syncRelations($member, $data);

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::MemberCreated,
        MemberTimelineEventType::MemberCreated,
        $member,
        'Member profile created.',
        $actor,
        null,
        ['membership_number' => $member->membership_number],
      );

      return $member->fresh(['tags', 'contacts', 'addresses']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Member $member, array $data, User $actor): Member
  {
    return DB::transaction(function () use ($member, $data, $actor): Member {
      $old = Arr::only($member->toArray(), [
        'status', 'approval_status', 'email', 'phone', 'country_id', 'region_id', 'ministry_id',
      ]);

      $member->fill(Arr::only($data, [
        'title', 'first_name', 'middle_name', 'last_name', 'display_name', 'gender', 'date_of_birth',
        'phone', 'alternate_phone', 'email', 'occupation', 'organization', 'marketplace_sector',
        'skills', 'languages', 'biography', 'country_id', 'city', 'state', 'region_id', 'ministry_id',
        'preferred_ministry_id', 'joined_at', 'profession', 'church_name', 'church_address',
        'years_of_experience', 'years_in_faith', 'ministry_interests', 'gifts', 'references',
        'education', 'availability', 'interview_notes', 'onboarding_notes',
      ]));
      $member->updated_by = $actor->id;
      $member->save();

      $this->syncRelations($member, $data);

      $this->auditService->record(
        MemberAuditEventType::MemberUpdated,
        $member,
        $actor,
        $old,
        Arr::only($member->fresh()->toArray(), array_keys($old)),
      );

      return $member->fresh(['tags', 'contacts', 'addresses']);
    });
  }

  public function transitionStatus(
    Member $member,
    MemberStatus $toStatus,
    User $actor,
    ?string $reason = null,
  ): Member {
    return DB::transaction(function () use ($member, $toStatus, $actor, $reason): Member {
      $fromStatus = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if (! $fromStatus->canTransitionTo($toStatus)) {
        throw new \App\Exceptions\BusinessException(
          "Cannot transition from {$fromStatus->value} to {$toStatus->value}.",
          \App\Enums\ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }

      $member->status = $toStatus;
      $member->updated_by = $actor->id;

      if ($toStatus === MemberStatus::Active && $member->joined_at === null) {
        $member->joined_at = now()->toDateString();
      }

      $member->save();

      MemberStatusTransition::query()->create([
        'member_id' => $member->id,
        'from_status' => $fromStatus->value,
        'to_status' => $toStatus->value,
        'actor_id' => $actor->id,
        'reason' => $reason,
      ]);

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::StatusChanged,
        MemberTimelineEventType::StatusChanged,
        $member,
        "Status changed from {$fromStatus->label()} to {$toStatus->label()}.",
        $actor,
        ['status' => $fromStatus->value],
        ['status' => $toStatus->value],
        ['reason' => $reason],
      );

      return $member->fresh();
    });
  }

  public function approve(Member $member, User $actor, ?string $reason = null): Member
  {
    return DB::transaction(function () use ($member, $actor, $reason): Member {
      $currentStatus = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if (! in_array($currentStatus, [
        MemberStatus::InterviewCompleted,
        MemberStatus::InterviewPassed,
        MemberStatus::Approved,
      ], true)) {
        throw new \App\Exceptions\BusinessException(
          'Member must complete interview before final approval.',
          \App\Enums\ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }

      if ($currentStatus !== MemberStatus::Approved) {
        $member = $this->transitionStatus($member, MemberStatus::Approved, $actor, $reason);
      }

      $member->approval_status = MemberApprovalStatus::Approved;
      $member->updated_by = $actor->id;
      $member->save();

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::MemberApproved,
        MemberTimelineEventType::Approved,
        $member,
        'Membership application approved.',
        $actor,
        null,
        ['approval_status' => MemberApprovalStatus::Approved->value],
        ['reason' => $reason],
      );

      app(MemberOnboardingService::class)->ensureChecklist($member);
      app(MemberOnboardingService::class)->autoComplete($member, 'application_approved', $actor);

      $hasPassedInterview = $member->interviews()
        ->where('status', \App\Enums\MemberInterviewStatus::Passed->value)
        ->exists()
        || $member->interviews()
          ->where('result', \App\Enums\MemberInterviewStatus::Passed->value)
          ->exists();

      if ($hasPassedInterview) {
        app(MemberOnboardingService::class)->autoComplete($member, 'interview_passed', $actor);
      }

      app(MemberAccountProvisioningService::class)->provisionOnApproval($member->fresh(), $actor);

      app(MemberNotificationQueueService::class)->queueMany($member->fresh(), [
        [
          'channel' => 'email',
          'template' => 'application_approved',
          'payload' => ['email' => $member->email, 'reason' => $reason],
        ],
        [
          'channel' => 'in_app',
          'template' => 'application_approved',
          'payload' => ['member_id' => $member->id],
        ],
      ]);

      $member = $member->fresh();
      $currentAfterApprove = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($currentAfterApprove === MemberStatus::Approved && $currentAfterApprove->canTransitionTo(MemberStatus::Orientation)) {
        $member = $this->transitionStatus($member, MemberStatus::Orientation, $actor, 'Onboarding / orientation started after approval.');
        app(MemberOnboardingService::class)->autoComplete($member, 'orientation_assigned', $actor);
      }

      return $member->fresh();
    });
  }

  public function startReview(Member $member, User $actor, ?string $reason = null): Member
  {
    $current = $member->status instanceof MemberStatus ? $member->status : MemberStatus::from((string) $member->status);

    return $this->transitionStatus($member, MemberStatus::UnderReview, $actor, $reason ?? 'Application review started.');
  }

  public function requireInterview(Member $member, User $actor, ?string $reason = null): Member
  {
    $current = $member->status instanceof MemberStatus ? $member->status : MemberStatus::from((string) $member->status);

    return $this->transitionStatus($member, MemberStatus::InterviewRequired, $actor, $reason ?? 'Interview required.');
  }

  public function requestMoreInformation(Member $member, User $actor, string $message): Member
  {
    return DB::transaction(function () use ($member, $actor, $message): Member {
      $member->onboarding_notes = trim(($member->onboarding_notes ?? '')."\n[More info requested] ".$message);
      $member->updated_by = $actor->id;
      $member->save();

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::OnboardingUpdated,
        MemberTimelineEventType::NoteAdded,
        $member,
        'Additional information requested from applicant.',
        $actor,
        null,
        null,
        ['message' => $message],
      );

      app(MemberNotificationQueueService::class)->queueMany($member, [
        [
          'channel' => 'email',
          'template' => 'request_more_information',
          'payload' => ['message' => $message, 'email' => $member->email],
        ],
        [
          'channel' => 'whatsapp',
          'template' => 'request_more_information',
          'payload' => ['message' => $message, 'phone' => $member->phone],
        ],
      ]);

      return $member->fresh();
    });
  }

  public function reject(Member $member, User $actor, ?string $reason = null): Member
  {
    return DB::transaction(function () use ($member, $actor, $reason): Member {
      $current = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($current->canTransitionTo(MemberStatus::Rejected)) {
        $member = $this->transitionStatus($member, MemberStatus::Rejected, $actor, $reason);
      }

      $member->approval_status = MemberApprovalStatus::Rejected;
      $member->updated_by = $actor->id;
      $member->save();

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::MemberRejected,
        MemberTimelineEventType::Rejected,
        $member,
        'Membership application rejected.',
        $actor,
        null,
        ['approval_status' => MemberApprovalStatus::Rejected->value],
        ['reason' => $reason],
      );

      return $member->fresh();
    });
  }

  public function delete(Member $member, User $actor): void
  {
    $member->delete();

    $this->auditService->record(
      MemberAuditEventType::MemberDeleted,
      $member,
      $actor,
    );
  }

  public function restore(int $memberId, User $actor): Member
  {
    $member = Member::query()->onlyTrashed()->findOrFail($memberId);
    $member->restore();

    $this->auditService->recordWithTimeline(
      MemberAuditEventType::MemberRestored,
      MemberTimelineEventType::StatusChanged,
      $member,
      'Member profile restored.',
      $actor,
    );

    return $member->fresh();
  }

  /**
   * @param  list<int>  $memberIds
   */
  public function bulk(BulkMemberAction $action, array $memberIds, User $actor, ?string $reason = null): int
  {
    $count = 0;
    $lastMember = null;

    foreach ($memberIds as $memberId) {
      $member = Member::query()->withTrashed()->find($memberId);
      if ($member === null) {
        continue;
      }

      match ($action) {
        BulkMemberAction::Approve => $this->approve($member, $actor, $reason),
        BulkMemberAction::Reject => $this->reject($member, $actor, $reason),
        BulkMemberAction::Activate => $this->activateMember($member, $actor, $reason),
        BulkMemberAction::Deactivate => $this->transitionStatus($member, MemberStatus::Inactive, $actor, $reason),
        BulkMemberAction::Archive => $this->transitionStatus($member, MemberStatus::Archived, $actor, $reason),
        BulkMemberAction::Delete => $this->delete($member, $actor),
        BulkMemberAction::Restore => $this->restore((int) $member->id, $actor),
      };

      $count++;
      $lastMember = $member;
    }

    if ($count > 0 && $lastMember !== null) {
      $this->auditService->record(
        MemberAuditEventType::BulkAction,
        $lastMember,
        $actor,
        metadata: ['action' => $action->value, 'member_ids' => $memberIds, 'count' => $count],
      );
    }

    return $count;
  }

  private function activateMember(Member $member, User $actor, ?string $reason = null): Member
  {
    $status = $member->status instanceof MemberStatus
      ? $member->status
      : MemberStatus::from((string) $member->status);

    if ($status === MemberStatus::Approved) {
      return $this->transitionStatus($member, MemberStatus::Active, $actor, $reason);
    }

    if (in_array($status, [MemberStatus::Suspended, MemberStatus::Inactive, MemberStatus::Archived], true)) {
      return $this->transitionStatus($member, MemberStatus::Active, $actor, $reason);
    }

    return $member;
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function syncRelations(Member $member, array $data): void
  {
    if (array_key_exists('tag_ids', $data)) {
      $member->tags()->sync($data['tag_ids'] ?? []);
    }

    if (! empty($data['contacts']) && is_array($data['contacts'])) {
      $member->contacts()->delete();
      foreach ($data['contacts'] as $contact) {
        $member->contacts()->create($contact);
      }
    }

    if (! empty($data['addresses']) && is_array($data['addresses'])) {
      $member->addresses()->delete();
      foreach ($data['addresses'] as $address) {
        $member->addresses()->create($address);
      }
    }
  }
}
