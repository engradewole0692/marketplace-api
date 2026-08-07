<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberOnboardingChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MemberOnboardingService implements ServiceContract
{
  public const DEFAULT_STEPS = [
    ['step_key' => 'membership_approved', 'label' => 'Membership approved', 'sort_order' => 1],
    ['step_key' => 'application_approved', 'label' => 'Application approved', 'sort_order' => 2],
    ['step_key' => 'interview_passed', 'label' => 'Interview passed', 'sort_order' => 3],
    ['step_key' => 'login_sent', 'label' => 'Login credentials sent', 'sort_order' => 4],
    ['step_key' => 'password_sent', 'label' => 'Temporary password sent', 'sort_order' => 5],
    ['step_key' => 'ministry_assigned', 'label' => 'Ministry assigned', 'sort_order' => 6],
    ['step_key' => 'country_assigned', 'label' => 'Country assigned', 'sort_order' => 7],
    ['step_key' => 'whatsapp_sent', 'label' => 'WhatsApp / community links sent', 'sort_order' => 8],
    ['step_key' => 'learning_portal_activated', 'label' => 'Learning portal activated', 'sort_order' => 9],
    ['step_key' => 'welcome_sent', 'label' => 'Welcome sent', 'sort_order' => 10],
    ['step_key' => 'orientation_assigned', 'label' => 'Orientation assigned', 'sort_order' => 11],
    ['step_key' => 'orientation_completed', 'label' => 'Orientation completed', 'sort_order' => 12],
    ['step_key' => 'portal_activated', 'label' => 'Member portal activated', 'sort_order' => 13],
    ['step_key' => 'profile_completed', 'label' => 'Profile completed', 'sort_order' => 14],
    ['step_key' => 'member_notified', 'label' => 'Member notified', 'sort_order' => 15],
    ['step_key' => 'onboarding_completed', 'label' => 'Onboarding completed', 'sort_order' => 16],
  ];

  public function __construct(
    private readonly MemberAuditService $auditService,
  ) {}

  public function ensureChecklist(Member $member): void
  {
    foreach (self::DEFAULT_STEPS as $step) {
      MemberOnboardingChecklistItem::query()->firstOrCreate(
        ['member_id' => $member->id, 'step_key' => $step['step_key']],
        [
          'label' => $step['label'],
          'sort_order' => $step['sort_order'],
          'is_completed' => false,
        ],
      );
    }
  }

  /**
   * @return list<MemberOnboardingChecklistItem>
   */
  public function checklist(Member $member): array
  {
    $this->ensureChecklist($member);

    return MemberOnboardingChecklistItem::query()
      ->where('member_id', $member->id)
      ->with('completer')
      ->orderBy('sort_order')
      ->get()
      ->all();
  }

  public function markStep(
    Member $member,
    string $stepKey,
    bool $completed,
    User $actor,
    ?string $notes = null,
  ): MemberOnboardingChecklistItem {
    return DB::transaction(function () use ($member, $stepKey, $completed, $actor, $notes): MemberOnboardingChecklistItem {
      $this->ensureChecklist($member);
      $item = MemberOnboardingChecklistItem::query()
        ->where('member_id', $member->id)
        ->where('step_key', $stepKey)
        ->firstOrFail();

      $item->is_completed = $completed;
      $item->completed_at = $completed ? now() : null;
      $item->completed_by = $completed ? $actor->id : null;
      if ($notes !== null) {
        $item->notes = $notes;
      }
      $item->save();

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::OnboardingUpdated,
        MemberTimelineEventType::OnboardingCompleted,
        $member,
        ($completed ? 'Completed' : 'Reopened').' onboarding step: '.$item->label,
        $actor,
        null,
        ['step_key' => $stepKey, 'completed' => $completed],
      );

      if ($stepKey === 'orientation_completed' && $completed) {
        $member->orientation_completed_at = now();
        $member->updated_by = $actor->id;
        $member->save();
      }

      return $item->fresh(['completer']);
    });
  }

  public function autoComplete(Member $member, string $stepKey, ?User $actor = null): void
  {
    $this->ensureChecklist($member);
    $item = MemberOnboardingChecklistItem::query()
      ->where('member_id', $member->id)
      ->where('step_key', $stepKey)
      ->first();

    if ($item === null || $item->is_completed) {
      return;
    }

    $item->is_completed = true;
    $item->completed_at = now();
    $item->completed_by = $actor?->id;
    $item->save();
  }
}
