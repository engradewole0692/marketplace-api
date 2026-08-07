<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberStatus;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use Illuminate\Support\Facades\DB;

/**
 * After interview PASS: approve, assign preferred ministry/country, provision credentials, activate portal.
 */
final class MemberPostInterviewAutomationService implements ServiceContract
{
  public function __construct(
    private readonly MemberManagementService $memberManagementService,
    private readonly MemberActivationService $activationService,
    private readonly MemberOnboardingService $onboardingService,
    private readonly MemberNotificationQueueService $notificationQueueService,
    private readonly MemberAuditService $auditService,
  ) {}

  public function runAfterInterviewPassed(Member $member, User $actor): Member
  {
    return DB::transaction(function () use ($member, $actor): Member {
      $member = $member->fresh(['preferredMinistry', 'country', 'ministry']) ?? $member;

      $current = $this->statusOf($member);

      if ($current->canTransitionTo(MemberStatus::InterviewPassed)) {
        $member = $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::InterviewPassed,
          $actor,
          'Interview passed — automatic onboarding started.',
        );
      } elseif ($current->canTransitionTo(MemberStatus::InterviewCompleted)) {
        $member = $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::InterviewCompleted,
          $actor,
          'Interview completed (passed).',
        );
      }

      $member = $this->memberManagementService->approve(
        $member->fresh() ?? $member,
        $actor,
        'Auto-approved after interview pass.',
      );

      $member = $member->fresh() ?? $member;
      $preferredId = $member->preferred_ministry_id ?? $member->ministry_id;
      if ($preferredId !== null && (int) $member->ministry_id !== (int) $preferredId) {
        $member = $this->activationService->assignMinistry($member, (int) $preferredId, $actor, true);
      } elseif ($preferredId !== null) {
        $this->onboardingService->autoComplete($member, 'ministry_assigned', $actor);
      }

      if ($member->country_id !== null) {
        $this->onboardingService->autoComplete($member, 'country_assigned', $actor);
        $this->auditService->recordWithTimeline(
          MemberAuditEventType::CountryAssigned,
          MemberTimelineEventType::AssignedCountry,
          $member,
          'Country confirmed from original application selection.',
          $actor,
          null,
          ['country_id' => $member->country_id],
        );
      }

      $this->sendMinistryAndCountryLinks($member);

      $member = $this->activationService->activate($member->fresh() ?? $member, $actor);

      foreach ([
        'onboarding_completed',
        'learning_portal_activated',
        'login_sent',
        'password_sent',
        'whatsapp_sent',
        'membership_approved',
      ] as $step) {
        $this->onboardingService->autoComplete($member, $step, $actor);
      }

      $member = $member->fresh() ?? $member;
      $status = $this->statusOf($member);

      if ($status !== MemberStatus::Active && $status->canTransitionTo(MemberStatus::OnboardingCompleted)) {
        $member = $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::OnboardingCompleted,
          $actor,
          'Automated onboarding completed.',
        );
        $status = $this->statusOf($member->fresh() ?? $member);
      }

      if ($status !== MemberStatus::Active && $status->canTransitionTo(MemberStatus::Active)) {
        $member = $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::Active,
          $actor,
          'Member fully activated after interview pass.',
        );
        $member->activated_at = $member->activated_at ?? now();
        $member->save();
      }

      return $member->fresh(['user', 'ministry', 'country', 'preferredMinistry']) ?? $member;
    });
  }

  private function statusOf(Member $member): MemberStatus
  {
    return $member->status instanceof MemberStatus
      ? $member->status
      : MemberStatus::from((string) $member->status);
  }

  private function sendMinistryAndCountryLinks(Member $member): void
  {
    $ministry = $member->ministry_id
      ? CmsMinistry::query()->find($member->ministry_id)
      : ($member->preferred_ministry_id ? CmsMinistry::query()->find($member->preferred_ministry_id) : null);

    $country = $member->country_id ? CmsCountry::query()->find($member->country_id) : null;
    $countryContent = is_array($country?->content) ? $country->content : [];
    $ministryContent = is_array($ministry?->content) ? $ministry->content : [];

    $payload = [
      'email' => $member->email,
      'ministry_name' => $ministry?->name,
      'whatsapp_link' => $ministry?->whatsapp_link ?: ($countryContent['whatsapp_link'] ?? null),
      'telegram_link' => $ministry?->telegram_link ?: ($countryContent['telegram_link'] ?? null),
      'signal_link' => $ministry?->signal_link ?? null,
      'slack_link' => $ministryContent['slack_link'] ?? ($countryContent['slack_link'] ?? null),
      'discord_link' => $countryContent['discord_link'] ?? ($ministryContent['discord_link'] ?? null),
      'country_name' => $country?->name,
      'country_whatsapp' => $countryContent['whatsapp_link'] ?? ($countryContent['whatsapp_group'] ?? null),
      'regional_group' => $countryContent['regional_group'] ?? null,
      'welcome_resources' => $countryContent['welcome_resources'] ?? null,
    ];

    $this->notificationQueueService->queueMany($member, [
      [
        'channel' => 'email',
        'template' => 'ministry_country_onboarding',
        'payload' => $payload,
      ],
      [
        'channel' => 'in_app',
        'template' => 'ministry_country_onboarding',
        'payload' => $payload,
      ],
      [
        'channel' => 'whatsapp',
        'template' => 'ministry_invitation',
        'payload' => [
          'phone' => $member->phone,
          'whatsapp_link' => $payload['whatsapp_link'],
        ],
      ],
    ]);
  }
}
