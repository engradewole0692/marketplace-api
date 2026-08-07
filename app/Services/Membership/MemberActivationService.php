<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberStatus;
use App\Enums\MemberTimelineEventType;
use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\MemberMinistryAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MemberActivationService implements ServiceContract
{
  public function __construct(
    private readonly MemberAuditService $auditService,
    private readonly MemberManagementService $memberManagementService,
    private readonly MemberNotificationQueueService $notificationQueueService,
    private readonly MemberOnboardingService $onboardingService,
  ) {}

  public function assignMinistry(Member $member, int $ministryId, User $actor, bool $primary = true): Member
  {
    return DB::transaction(function () use ($member, $ministryId, $actor, $primary): Member {
      if ($primary) {
        MemberMinistryAssignment::query()
          ->where('member_id', $member->id)
          ->where('is_primary', true)
          ->update(['is_primary' => false]);
      }

      MemberMinistryAssignment::query()->updateOrCreate(
        ['member_id' => $member->id, 'ministry_id' => $ministryId],
        [
          'role' => 'member',
          'is_primary' => $primary,
          'assigned_at' => now(),
          'assigned_by' => $actor->id,
        ],
      );

      if ($primary) {
        $member->ministry_id = $ministryId;
        $member->updated_by = $actor->id;
        $member->save();
      }

      $current = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($current->canTransitionTo(MemberStatus::MinistryAssigned)) {
        $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::MinistryAssigned,
          $actor,
          'Ministry assignment recorded.',
        );
      }

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::MinistryAssigned,
        MemberTimelineEventType::AssignedMinistry,
        $member,
        'Member assigned to ministry.',
        $actor,
        null,
        ['ministry_id' => $ministryId, 'primary' => $primary],
      );

      $this->onboardingService->autoComplete($member, 'ministry_assigned', $actor);

      return $member->fresh(['ministry', 'ministryAssignments.ministry']);
    });
  }

  public function completeOrientation(Member $member, User $actor, ?string $notes = null): Member
  {
    return DB::transaction(function () use ($member, $actor, $notes): Member {
      if ($notes !== null) {
        $member->onboarding_notes = trim(($member->onboarding_notes ?? '')."\n".$notes);
      }
      $member->orientation_completed_at = now();
      $member->updated_by = $actor->id;
      $member->save();

      $current = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($current->canTransitionTo(MemberStatus::Orientation)) {
        $this->memberManagementService->transitionStatus($member, MemberStatus::Orientation, $actor, 'Orientation in progress.');
      }

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::OnboardingUpdated,
        MemberTimelineEventType::OnboardingCompleted,
        $member,
        'Orientation notes recorded.',
        $actor,
      );

      $this->onboardingService->autoComplete($member, 'orientation_assigned', $actor);
      $this->onboardingService->autoComplete($member, 'orientation_completed', $actor);

      $current = $member->fresh()->status instanceof MemberStatus
        ? $member->fresh()->status
        : MemberStatus::from((string) $member->fresh()->status);

      // After orientation, keep members in the ministry assignment queue.
      if ($member->ministry_id === null && $current === MemberStatus::Orientation) {
        // Status remains orientation (assignment queue includes orientation without ministry).
      }

      return $member->fresh();
    });
  }

  public function activate(Member $member, User $actor, ?string $temporaryPassword = null): Member
  {
    return DB::transaction(function () use ($member, $actor, $temporaryPassword): Member {
      if ($member->email === null || $member->email === '') {
        throw new \App\Exceptions\BusinessException(
          'Member email is required before activation.',
          \App\Enums\ApiErrorCode::UnprocessableEntity,
          null,
          422,
        );
      }

      $current = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($current === MemberStatus::Approved && $current->canTransitionTo(MemberStatus::Orientation)) {
        $member = $this->memberManagementService->transitionStatus($member, MemberStatus::Orientation, $actor, 'Orientation started.');
        $this->onboardingService->autoComplete($member, 'orientation_assigned', $actor);
      }

      $current = $member->fresh()->status instanceof MemberStatus
        ? $member->fresh()->status
        : MemberStatus::from((string) $member->fresh()->status);

      if ($member->ministry_id !== null && $current->canTransitionTo(MemberStatus::MinistryAssigned)) {
        $member = $this->memberManagementService->transitionStatus($member, MemberStatus::MinistryAssigned, $actor, 'Ministry assignment confirmed.');
      }

      $user = $member->user;
      if ($user === null) {
        $password = $temporaryPassword
          ?? app(MemberCredentialPasswordService::class)->generate($member);
        $user = User::query()->create([
          'first_name' => $member->first_name,
          'last_name' => $member->last_name,
          'display_name' => $member->display_name,
          'email' => $member->email,
          'username' => $member->email,
          'phone' => $member->phone,
          'status' => UserStatus::Active,
          'password' => $password,
          'must_change_password' => true,
        ]);

        $memberRole = Role::query()->where('slug', 'member')->first();
        if ($memberRole !== null) {
          $user->roles()->syncWithoutDetaching([$memberRole->id]);
        }

        foreach (['member.portal', 'learner.portal'] as $slug) {
          $permission = \App\Models\Permission::query()->where('slug', $slug)->first();
          if ($permission !== null) {
            $user->permissions()->syncWithoutDetaching([$permission->id]);
            if ($memberRole !== null) {
              $memberRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
          }
        }

        $member->user_id = $user->id;
        $this->auditService->recordWithTimeline(
          MemberAuditEventType::MemberActivated,
          MemberTimelineEventType::AccountCreated,
          $member,
          'Member login account created.',
          $actor,
          null,
          ['user_id' => $user->id],
        );
      }

      $current = $member->status instanceof MemberStatus
        ? $member->status
        : MemberStatus::from((string) $member->status);

      if ($current->canTransitionTo(MemberStatus::Active)) {
        $this->memberManagementService->transitionStatus($member, MemberStatus::Active, $actor, 'Member account activated.');
      } elseif ($current === MemberStatus::MinistryAssigned) {
        $this->memberManagementService->transitionStatus($member, MemberStatus::Active, $actor, 'Member account activated.');
      }

      $member->refresh();
      $member->activated_at = now();
      $member->updated_by = $actor->id;
      $member->save();

      $this->notificationQueueService->queueMany($member, [
        ['channel' => 'email', 'template' => 'member_welcome', 'payload' => ['email' => $member->email]],
        ['channel' => 'whatsapp', 'template' => 'member_invitation', 'payload' => ['phone' => $member->phone]],
        ['channel' => 'in_app', 'template' => 'member_welcome', 'payload' => ['user_id' => $member->user_id]],
      ]);

      $this->onboardingService->autoComplete($member, 'welcome_sent', $actor);
      $this->onboardingService->autoComplete($member, 'portal_activated', $actor);
      $this->onboardingService->autoComplete($member, 'member_notified', $actor);
      if ($member->ministry_id) {
        $this->onboardingService->autoComplete($member, 'ministry_assigned', $actor);
      }

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::MemberActivated,
        MemberTimelineEventType::Activated,
        $member,
        'Member account activated and welcome notifications queued.',
        $actor,
      );

      return $member->fresh(['user', 'ministry', 'ministryAssignments.ministry']);
    });
  }

  /**
   * Full post-approval automation: orientation → ministry (if set) → activate.
   */
  public function runPostApprovalAutomation(Member $member, User $actor): Member
  {
    if ($member->ministry_id === null && $member->preferred_ministry_id !== null) {
      $member = $this->assignMinistry($member, (int) $member->preferred_ministry_id, $actor, true);
    }

    return $this->activate($member, $actor);
  }
}
