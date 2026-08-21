<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\ClientType;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingService;
use App\Modules\Counselling\Models\Counsellor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CounsellingCaseService implements ServiceContract
{
  public function __construct(
    private readonly CounsellingAuditService $auditService,
    private readonly CounsellingNotificationService $notificationService,
    private readonly CounsellingPaymentService $paymentService,
  ) {}

  /**
   * @param  array<string, mixed>  $data
   */
  public function createFromRequest(array $data, ?User $actor = null): CounsellingCase
  {
    return DB::transaction(function () use ($data, $actor): CounsellingCase {
      $service = CounsellingService::query()->where('uuid', $data['service_id'] ?? '')->firstOrFail();
      $member = null;
      $user = $actor;

      if (! empty($data['member_id'])) {
        $member = Member::query()->where('uuid', $data['member_id'])->first();
      } elseif ($user !== null) {
        $member = Member::query()->where('user_id', $user->id)->first();
      }

      if ($member !== null && $user === null) {
        $user = $member->user;
      }

      $clientType = $member !== null ? ClientType::Member : ClientType::Visitor;

      $sourceSubmissionId = null;
      if (! empty($data['source_submission_id'])) {
        $sourceSubmissionId = CmsFormSubmission::query()
          ->where('uuid', $data['source_submission_id'])
          ->value('id');
      }

      $categoryId = $service->category_id;
      if (! empty($data['category_id'])) {
        $categoryId = \App\Modules\Counselling\Models\CounsellingCategory::query()
          ->where('uuid', $data['category_id'])
          ->value('id') ?? $categoryId;
      }

      $initialStatus = CaseStatus::Submitted;

      $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
      foreach (['subject', 'preferred_language', 'urgency', 'terms_accepted', 'who_is_this_for'] as $metaKey) {
        if (array_key_exists($metaKey, $data)) {
          $metadata[$metaKey] = $data[$metaKey];
        }
      }

      $case = CounsellingCase::query()->create([
        'case_number' => $this->generateCaseNumber(),
        'service_id' => $service->id,
        'category_id' => $categoryId,
        'user_id' => $user?->id,
        'member_id' => $member?->id,
        'source_submission_id' => $sourceSubmissionId,
        'client_type' => $clientType,
        'status' => $initialStatus,
        'preferred_format' => $data['preferred_format'] ?? $service->format?->value ?? $service->format,
        'client_name' => $data['client_name'] ?? $member?->fullName() ?? $user?->name ?? '',
        'client_email' => $data['client_email'] ?? $member?->email ?? $user?->email ?? '',
        'client_phone' => $data['client_phone'] ?? $member?->phone ?? null,
        'client_country' => $data['client_country'] ?? null,
        'client_gender' => $data['client_gender'] ?? $member?->gender ?? null,
        'who_is_this_for' => $data['who_is_this_for'] ?? ($metadata['who_is_this_for'] ?? null),
        'preferred_counsellor_gender' => $data['preferred_counsellor_gender'] ?? null,
        'reason' => $data['reason'] ?? $data['description'] ?? null,
        'prayer_request' => $data['prayer_request'] ?? null,
        'preferred_at' => $data['preferred_at'] ?? null,
        'timezone' => $data['timezone'] ?? 'UTC',
        'member_snapshot' => $member !== null ? $this->buildMemberSnapshot($member) : null,
        'metadata' => $metadata !== [] ? $metadata : null,
        'created_by_user_id' => $actor?->id,
      ]);

      $this->auditService->record(
        $case,
        $actor,
        'case.created',
        'Application Submitted',
        'Case '.$case->case_number.' was submitted.',
        ['status' => $case->status->value],
      );

      $fresh = $case->fresh(['service', 'category', 'member', 'user']);
      $this->notificationService->notifyRequestSubmitted($fresh);
      $this->notificationService->notifyAdminsNewRequest($fresh);

      return $fresh;
    });
  }

  public function transitionStatus(
    CounsellingCase $case,
    CaseStatus $next,
    ?User $actor = null,
    ?string $note = null,
  ): CounsellingCase {
    $current = $case->status instanceof CaseStatus
      ? $case->status
      : CaseStatus::tryFrom((string) $case->status) ?? CaseStatus::Submitted;

    if (! $current->canTransitionTo($next)) {
      throw ValidationException::withMessages([
        'status' => ['Invalid status transition from '.$current->value.' to '.$next->value.'.'],
      ]);
    }

    $previous = $current->value;
    $case->status = $next;

    if ($next === CaseStatus::Completed) {
      $case->completed_at = now();
    }

    if ($next === CaseStatus::Closed) {
      $case->completed_at = $case->completed_at ?? now();
    }

    $case->save();

    $this->auditService->record(
      $case,
      $actor,
      'case.status_changed',
      'Status updated to '.$next->label(),
      $note,
      ['from' => $previous, 'to' => $next->value],
    );

    $fresh = $case->fresh(['service', 'counsellor.user']);

    if ($next === CaseStatus::UnderReview || $next === CaseStatus::PendingReview) {
      $this->notificationService->notifyCaseAccepted($fresh);
    } elseif ($next === CaseStatus::Rejected) {
      $this->notificationService->notifyCaseRejected($fresh, $note);
    } elseif ($next === CaseStatus::AwaitingClient || $next === CaseStatus::AwaitingResponse) {
      $this->notificationService->notifyMoreInfoRequested($fresh, $note);
    } elseif ($next === CaseStatus::Completed) {
      $this->notificationService->notifyCompleted($fresh);
      $this->notificationService->notifyFeedbackRequest($fresh);
    } elseif ($next === CaseStatus::Closed) {
      $this->notificationService->notifyCaseClosed($fresh);
    } elseif ($next === CaseStatus::Cancelled) {
      $this->notificationService->notifyCancelled($fresh, $note);
    }

    return $fresh;
  }

  public function cancel(CounsellingCase $case, ?User $actor = null, ?string $reason = null): CounsellingCase
  {
    if (! $case->allow_cancel) {
      throw new BusinessException('This case cannot be cancelled.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    return DB::transaction(function () use ($case, $actor, $reason): CounsellingCase {
      $case->status = CaseStatus::Cancelled;
      $case->cancelled_at = now();
      $case->cancellation_reason = $reason;
      $case->save();

      $this->auditService->record(
        $case,
        $actor,
        'case.cancelled',
        'Case cancelled',
        $reason,
      );

      $this->notificationService->notifyCancelled($case->fresh(['service']), $reason);

      return $case->fresh(['service', 'counsellor.user']);
    });
  }

  public function assignCounsellor(
    CounsellingCase $case,
    Counsellor $counsellor,
    ?User $actor = null,
  ): CounsellingCase {
    return DB::transaction(function () use ($case, $counsellor, $actor): CounsellingCase {
      $case->counsellor_id = $counsellor->id;
      $case->assigned_at = now();

      $normalized = $case->status instanceof CaseStatus
        ? $case->status->normalize()
        : CaseStatus::tryFrom((string) $case->status)?->normalize();

      if (in_array($normalized, [CaseStatus::Submitted, CaseStatus::UnderReview, CaseStatus::WaitingPayment], true)) {
        $case->status = CaseStatus::Assigned;
      }

      $case->save();

      $this->auditService->record(
        $case,
        $actor,
        'case.counsellor_assigned',
        'Assigned to '.$counsellor->display_name,
        $counsellor->display_name.' was assigned to this case.',
        ['counsellor_id' => $counsellor->uuid],
      );

      $fresh = $case->fresh(['service', 'counsellor.user']);
      $this->notificationService->notifyCounsellorAssigned($fresh, $counsellor);
      $this->notificationService->notifyClientCounsellorAssigned($fresh);

      return $fresh;
    });
  }

  /**
   * @return array<string, mixed>
   */
  public function buildMemberSnapshot(Member $member): array
  {
    $member->loadMissing(['country', 'ministry', 'user']);

    return [
      'member_id' => $member->uuid,
      'membership_number' => $member->membership_number,
      'display_name' => $member->fullName(),
      'email' => $member->email ?? $member->user?->email,
      'phone' => $member->phone,
      'gender' => $member->gender,
      'date_of_birth' => $member->date_of_birth?->toDateString(),
      'country' => $member->country?->name,
      'city' => $member->city,
      'state' => $member->state,
      'ministry' => $member->ministry?->name,
      'occupation' => $member->occupation,
      'languages' => $member->languages ?? [],
      'status' => $member->status instanceof \BackedEnum ? $member->status->value : $member->status,
      'captured_at' => now()->toIso8601String(),
    ];
  }

  private function generateCaseNumber(): string
  {
    $prefix = 'CC-'.now()->format('Ymd').'-';
    $count = CounsellingCase::query()
      ->where('case_number', 'like', $prefix.'%')
      ->count();

    return sprintf('%s%04d', $prefix, $count + 1);
  }
}
